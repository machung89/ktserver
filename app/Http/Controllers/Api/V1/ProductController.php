<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\InventoryTransactionItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrderItem;
use App\Services\ActivityLogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ScopedByOrganization;

    public function __construct(protected ActivityLogService $activityLog) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $sortable = ['name', 'code', 'price', 'cost_price', 'created_at'];
        $sortBy = in_array($request->sort_by, $sortable) ? $request->sort_by : 'created_at';
        $sortDir = $request->sort_dir === 'asc' ? 'asc' : 'desc';

        $products = Product::with(['category.parent', 'units', 'recipe.ingredients.ingredient:id,name,unit,code'])
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%"))
            ->when($request->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->boolean('uncategorized'), fn ($q) => $q->whereNull('category_id'))
            ->when($request->boolean('active_only', false), fn ($q) => $q->where('is_active', true))
            ->when($request->product_type, fn ($q, $v) => $q->where('product_type', $v))
            ->orderBy($sortBy, $sortDir)
            ->paginate((int) ($request->per_page ?? 20));

        return ProductResource::collection($products);
    }

    public function lookupBarcode(Request $request): ProductResource|JsonResponse
    {
        $barcode = $request->query('barcode', '');
        $product = Product::with(['category.parent', 'units'])
            ->where('barcode', $barcode)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy sản phẩm với mã vạch này'], 404);
        }

        return new ProductResource($product);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', Rule::unique('products', 'code')->where('organization_id', $this->orgId())],
            'barcode' => ['nullable', 'string', Rule::unique('products', 'barcode')->where('organization_id', $this->orgId())],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string'],
            'category_id' => ['nullable', 'exists:product_categories,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'standard_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'weight' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'product_type' => ['nullable', 'in:product,ingredient'],
            'units' => ['nullable', 'array'],
            'units.*.name' => ['required', 'string'],
            'units.*.conversion_factor' => ['required', 'numeric', 'min:0.0001'],
            'combo_components' => ['nullable', 'array'],
            'combo_components.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'combo_components.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'combo_components.*.unit' => ['nullable', 'string', 'max:50'],
        ]);

        if (array_key_exists('standard_price', $validated) && $validated['standard_price'] === null) {
            $validated['standard_price'] = 0;
        }

        $product = Product::create(array_merge(
            collect($validated)->except(['units', 'combo_components'])->all(),
            ['organization_id' => $this->orgId()]
        ));

        $this->syncUnits($product, $validated['units'] ?? []);
        $this->syncCombo($product, $validated['combo_components'] ?? null);

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'product_created',
            "Tạo sản phẩm #{$product->code} - {$product->name}",
            null, [], 'product', $product->id
        );

        return (new ProductResource($product->load(['units', 'recipe.ingredients.ingredient:id,name,unit,code'])))->response()->setStatusCode(201);
    }

    /**
     * Tạo/cập nhật/xóa combo (recipe type=combo) cho sản phẩm.
     * - $components null  => không đụng tới (không gửi từ form).
     * - $components rỗng  => xóa combo nếu có.
     * - $components có    => upsert combo với các thành phần.
     */
    private function syncCombo(Product $product, ?array $components): void
    {
        if ($components === null) {
            return;
        }

        if (empty($components)) {
            $product->recipe()->where('type', 'combo')->delete();

            return;
        }

        $recipe = $product->recipe()->firstOrNew([]);
        $recipe->fill([
            'organization_id' => $this->orgId(),
            'type' => 'combo',
            'yield_quantity' => 1,
        ])->save();

        $recipe->ingredients()->delete();
        $recipe->ingredients()->createMany(collect($components)->map(fn ($c) => [
            'ingredient_id' => $c['product_id'],
            'quantity' => $c['quantity'],
            'unit' => $c['unit'] ?? null,
        ])->all());
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load(['category.parent', 'units', 'recipe.ingredients.ingredient:id,name,unit,code']));
    }

    public function update(Request $request, Product $product): ProductResource
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', Rule::unique('products', 'code')->ignore($product->id)->where('organization_id', $this->orgId())],
            'barcode' => ['nullable', 'string', Rule::unique('products', 'barcode')->ignore($product->id)->where('organization_id', $this->orgId())],
            'name' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'unit' => ['sometimes', 'string'],
            'category_id' => ['nullable', 'exists:product_categories,id'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'standard_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'numeric', 'min:0'],
            'weight' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'product_type' => ['nullable', 'in:product,ingredient'],
            'units' => ['nullable', 'array'],
            'units.*.name' => ['required_with:units', 'string'],
            'units.*.conversion_factor' => ['required_with:units', 'numeric', 'min:0.0001'],
            'combo_components' => ['nullable', 'array'],
            'combo_components.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'combo_components.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'combo_components.*.unit' => ['nullable', 'string', 'max:50'],
        ]);

        if (array_key_exists('standard_price', $validated) && $validated['standard_price'] === null) {
            $validated['standard_price'] = 0;
        }

        $product->update(collect($validated)->except(['units', 'combo_components'])->all());

        if (array_key_exists('units', $validated)) {
            $this->syncUnits($product, $validated['units'] ?? []);
        }

        if (array_key_exists('combo_components', $validated)) {
            $this->syncCombo($product, $validated['combo_components']);
        }

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'product_updated',
            "Cập nhật sản phẩm #{$product->code} - {$product->name}",
            null, [], 'product', $product->id
        );

        return new ProductResource($product->load(['category.parent', 'units', 'recipe.ingredients.ingredient:id,name,unit,code']));
    }

    public function destroy(Product $product): JsonResponse
    {
        $salesCount = SalesOrderItem::where('product_id', $product->id)->count();
        $purchaseCount = PurchaseOrderItem::where('product_id', $product->id)->count();
        $txCount = InventoryTransactionItem::where('product_id', $product->id)->count();

        if ($salesCount + $purchaseCount + $txCount > 0) {
            $parts = array_filter([
                $salesCount > 0 ? "{$salesCount} dòng đơn bán" : null,
                $purchaseCount > 0 ? "{$purchaseCount} dòng đơn nhập" : null,
                $txCount > 0 ? "{$txCount} giao dịch kho" : null,
            ]);

            return response()->json([
                'message' => 'Không thể xóa vì sản phẩm đang được sử dụng: '.implode(', ', $parts).'.',
            ], 422);
        }

        $code = $product->code;
        $name = $product->name;
        $id = $product->id;

        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }
        $product->delete();

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'product_deleted',
            "Xóa sản phẩm #{$code} - {$name}",
            null, [], 'product', $id
        );

        return response()->json(null, 204);
    }

    public function uploadImage(Request $request, Product $product): ProductResource
    {
        $request->validate(['image' => ['required', 'image', 'max:2048']]);

        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $path = $request->file('image')->store('products/'.$product->organization_id, 'public');
        $product->update(['image_path' => $path]);

        return new ProductResource($product->load(['category.parent', 'units']));
    }

    public function deleteImage(Product $product): JsonResponse
    {
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $product->update(['image_path' => null]);
        }

        return response()->json(null, 204);
    }

    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate(['rows' => ['required', 'array', 'min:1']]);

        $orgId = $this->orgId();
        $updateExisting = (bool) $request->boolean('update_existing', false);
        $allowedUpdateFields = ['name', 'unit', 'price', 'cost_price', 'standard_price', 'unit2', 'barcode', 'description', 'category_name'];
        $updateFields = $updateExisting
            ? array_intersect($request->input('update_fields', $allowedUpdateFields), $allowedUpdateFields)
            : [];

        $existingProducts = Product::where('organization_id', $orgId)
            ->get()
            ->keyBy(fn ($p) => strtolower($p->code));

        $categories = ProductCategory::where('organization_id', $orgId)
            ->get()
            ->keyBy(fn ($c) => strtolower(trim($c->name)));

        $success = 0;
        $updated = 0;
        $errors = [];

        foreach ($request->rows as $i => $row) {
            $rowNum = $i + 2;
            $v = Validator::make($row, [
                'code' => 'required|string',
                'name' => 'required|string',
                'unit' => 'required|string',
                'price' => 'required|numeric|min:0',
                'cost_price' => 'required|numeric|min:0',
            ]);

            if ($v->fails()) {
                $errors[] = ['row' => $rowNum, 'code' => $row['code'] ?? '—', 'reason' => implode('; ', $v->errors()->all())];

                continue;
            }

            $code = trim($row['code']);
            $existing = $existingProducts[strtolower($code)] ?? null;

            if ($existing && ! $updateExisting) {
                $errors[] = ['row' => $rowNum, 'code' => $code, 'reason' => 'Mã sản phẩm đã tồn tại'];

                continue;
            }

            try {
                $categoryId = null;
                $categoryName = trim($row['category_name'] ?? '');
                if ($categoryName !== '') {
                    $key = strtolower($categoryName);
                    if (! isset($categories[$key])) {
                        $cat = ProductCategory::create(['organization_id' => $orgId, 'name' => $categoryName]);
                        $categories[$key] = $cat;
                    }
                    $categoryId = $categories[$key]->id;
                }

                $allData = [
                    'name' => trim($row['name']),
                    'unit' => trim($row['unit']),
                    'price' => (float) $row['price'],
                    'cost_price' => (float) $row['cost_price'],
                    'standard_price' => isset($row['standard_price']) && $row['standard_price'] !== null && $row['standard_price'] !== '' ? (float) $row['standard_price'] : 0,
                    'barcode' => ($row['barcode'] ?? null) ?: null,
                    'description' => ($row['description'] ?? null) ?: null,
                    'category_id' => $categoryId,
                ];

                if ($existing) {
                    $updateCols = array_diff($updateFields, ['unit2', 'category_name']);
                    $fieldsToUpdate = array_intersect_key($allData, array_flip($updateCols));
                    if ($categoryId !== null && in_array('category_name', $updateFields)) {
                        $fieldsToUpdate['category_id'] = $categoryId;
                    }
                    if (! empty($fieldsToUpdate)) {
                        $existing->update($fieldsToUpdate);
                    }
                    $product = $existing;
                    $updated++;
                } else {
                    $product = Product::create(array_merge($allData, [
                        'organization_id' => $orgId,
                        'code' => $code,
                        'is_active' => true,
                    ]));
                    $existingProducts[strtolower($code)] = $product;
                    $success++;
                }

                $unit2Name = trim($row['unit2'] ?? '');
                $unit2Factor = (float) ($row['unit2_factor'] ?? 0);
                $shouldSyncUnit2 = ! $existing || in_array('unit2', $updateFields);
                if ($shouldSyncUnit2 && $unit2Name !== '' && $unit2Factor > 0) {
                    $this->syncUnits($product, [['name' => $unit2Name, 'conversion_factor' => $unit2Factor]]);
                }
            } catch (QueryException $e) {
                $msg = $e->getMessage();
                $reason = (str_contains($msg, 'products_code_unique') || str_contains($msg, "for key 'code'"))
                    ? 'Mã sản phẩm đã tồn tại'
                    : (str_contains($msg, 'Duplicate entry') ? 'Trùng dữ liệu (mã vạch/đơn vị...)' : 'Lỗi dữ liệu: '.Str::limit($msg, 120));
                $errors[] = ['row' => $rowNum, 'code' => $code, 'reason' => $reason];
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNum, 'code' => $code, 'reason' => 'Lỗi: '.Str::limit($e->getMessage(), 150)];
            }
        }

        if ($success > 0 || $updated > 0) {
            $this->activityLog->log(
                $orgId, Auth::id(),
                'product_imported',
                "Nhập khẩu sản phẩm: thêm mới {$success}, cập nhật {$updated}".(count($errors) > 0 ? ', lỗi '.count($errors) : ''),
                null,
                ['success' => $success, 'updated' => $updated, 'failed' => count($errors)],
            );
        }

        return response()->json([
            'success' => $success,
            'updated' => $updated,
            'failed' => count($errors),
            'errors' => $errors,
        ]);
    }

    /** @param  array<array{name: string, conversion_factor: float}>  $units */
    private function syncUnits(Product $product, array $units): void
    {
        $product->units()->delete();

        foreach ($units as $unit) {
            $product->units()->create([
                'name' => $unit['name'],
                'conversion_factor' => $unit['conversion_factor'],
            ]);
        }
    }
}
