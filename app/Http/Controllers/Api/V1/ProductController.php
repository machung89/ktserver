<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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

        $products = Product::with(['category.parent', 'units'])
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%"))
            ->when($request->category_id, fn ($q, $v) => $q->where('category_id', $v))
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
            'is_active' => ['boolean'],
            'product_type' => ['nullable', 'in:product,ingredient'],
            'units' => ['nullable', 'array'],
            'units.*.name' => ['required', 'string'],
            'units.*.conversion_factor' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $product = Product::create(array_merge(
            collect($validated)->except('units')->all(),
            ['organization_id' => $this->orgId()]
        ));

        $this->syncUnits($product, $validated['units'] ?? []);

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'product_created',
            "Tạo sản phẩm #{$product->code} - {$product->name}",
            null, [], 'product', $product->id
        );

        return (new ProductResource($product->load('units')))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load(['category.parent', 'units']));
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
            'is_active' => ['boolean'],
            'product_type' => ['nullable', 'in:product,ingredient'],
            'units' => ['nullable', 'array'],
            'units.*.name' => ['required_with:units', 'string'],
            'units.*.conversion_factor' => ['required_with:units', 'numeric', 'min:0.0001'],
        ]);

        $product->update(collect($validated)->except('units')->all());

        if (array_key_exists('units', $validated)) {
            $this->syncUnits($product, $validated['units'] ?? []);
        }

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'product_updated',
            "Cập nhật sản phẩm #{$product->code} - {$product->name}",
            null, [], 'product', $product->id
        );

        return new ProductResource($product->load(['category.parent', 'units']));
    }

    public function destroy(Product $product): JsonResponse
    {
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
                    'standard_price' => isset($row['standard_price']) && $row['standard_price'] !== null && $row['standard_price'] !== '' ? (float) $row['standard_price'] : null,
                    'barcode' => $row['barcode'] ?: null,
                    'description' => $row['description'] ?: null,
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
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNum, 'code' => $code, 'reason' => 'Lỗi không xác định'];
            }
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
