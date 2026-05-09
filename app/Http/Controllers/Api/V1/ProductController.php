<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::with('category.parent')
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%"))
            ->when($request->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->boolean('active_only', false), fn ($q) => $q->where('is_active', true))
            ->paginate(20);

        return ProductResource::collection($products);
    }

    public function lookupBarcode(Request $request): ProductResource|JsonResponse
    {
        $barcode = $request->query('barcode', '');
        $product = Product::with('category.parent')
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
        ]);

        $product = Product::create(array_merge($validated, ['organization_id' => $this->orgId()]));

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load('category.parent'));
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
        ]);

        $product->update($validated);

        return new ProductResource($product->load('category.parent'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }
}
