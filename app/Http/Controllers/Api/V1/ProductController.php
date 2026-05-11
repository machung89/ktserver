<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::with(['category.parent', 'units'])
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%"))
            ->when($request->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->boolean('active_only', false), fn ($q) => $q->where('is_active', true))
            ->paginate(20);

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
            'cost_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'units' => ['nullable', 'array'],
            'units.*.name' => ['required', 'string'],
            'units.*.conversion_factor' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $product = Product::create(array_merge(
            collect($validated)->except('units')->all(),
            ['organization_id' => $this->orgId()]
        ));

        $this->syncUnits($product, $validated['units'] ?? []);

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
            'cost_price' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'units' => ['nullable', 'array'],
            'units.*.name' => ['required_with:units', 'string'],
            'units.*.conversion_factor' => ['required_with:units', 'numeric', 'min:0.0001'],
        ]);

        $product->update(collect($validated)->except('units')->all());

        if (array_key_exists('units', $validated)) {
            $this->syncUnits($product, $validated['units'] ?? []);
        }

        return new ProductResource($product->load(['category.parent', 'units']));
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }

    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate(['rows' => ['required', 'array', 'min:1']]);

        $orgId = $this->orgId();
        $existingCodes = Product::pluck('code')
            ->map(fn ($c) => strtolower($c))
            ->flip()
            ->all();

        $success = 0;
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
            if (isset($existingCodes[strtolower($code)])) {
                $errors[] = ['row' => $rowNum, 'code' => $code, 'reason' => 'Mã sản phẩm đã tồn tại'];

                continue;
            }

            try {
                Product::create([
                    'organization_id' => $orgId,
                    'code' => $code,
                    'name' => trim($row['name']),
                    'unit' => trim($row['unit']),
                    'price' => (float) $row['price'],
                    'cost_price' => (float) $row['cost_price'],
                    'barcode' => $row['barcode'] ?: null,
                    'description' => $row['description'] ?: null,
                    'is_active' => true,
                ]);
                $existingCodes[strtolower($code)] = true;
                $success++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNum, 'code' => $code, 'reason' => 'Lỗi không xác định'];
            }
        }

        return response()->json(['success' => $success, 'failed' => count($errors), 'errors' => $errors]);
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
