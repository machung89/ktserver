<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductCategoryResource;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ProductCategoryController extends Controller
{
    use ScopedByOrganization;

    public function index(): AnonymousResourceCollection
    {
        $categories = ProductCategory::with('children')
            ->whereNull('parent_id')
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return ProductCategoryResource::collection($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:product_categories,id'],
            'description' => ['nullable', 'string'],
        ]);

        $category = ProductCategory::create(array_merge($validated, [
            'organization_id' => $this->orgId(),
        ]));

        return (new ProductCategoryResource($category->load('parent')))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, ProductCategory $productCategory): ProductCategoryResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:product_categories,id'],
            'description' => ['nullable', 'string'],
        ]);

        if (isset($validated['parent_id']) && $validated['parent_id'] === $productCategory->id) {
            throw ValidationException::withMessages(['parent_id' => ['Không thể chọn chính nó làm cha.']]);
        }

        $productCategory->update($validated);

        return new ProductCategoryResource($productCategory->load('parent'));
    }

    public function destroy(ProductCategory $productCategory): JsonResponse
    {
        if ($productCategory->products()->exists()) {
            throw ValidationException::withMessages(['id' => ['Không thể xóa nhóm hàng đang có sản phẩm.']]);
        }

        $productCategory->delete();

        return response()->json(null, 204);
    }
}
