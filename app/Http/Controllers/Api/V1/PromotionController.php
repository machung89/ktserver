<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PromotionResource;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        $promotions = Promotion::with(['products', 'categories'])
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->orderByDesc('created_at')
            ->get();

        return PromotionResource::collection($promotions);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePromotion($request);

        $promotion = Promotion::create(array_merge(
            collect($validated)->except(['product_ids', 'category_ids'])->toArray(),
            ['organization_id' => $this->orgId()]
        ));

        $this->syncRelations($promotion, $request);

        return (new PromotionResource($promotion->load(['products', 'categories'])))
            ->response()->setStatusCode(201);
    }

    public function show(Promotion $promotion): PromotionResource
    {
        return new PromotionResource($promotion->load(['products.units', 'categories']));
    }

    public function update(Request $request, Promotion $promotion): PromotionResource
    {
        $validated = $this->validatePromotion($request, $promotion);

        $promotion->update(collect($validated)->except(['product_ids', 'category_ids'])->toArray());

        $this->syncRelations($promotion, $request);

        return new PromotionResource($promotion->load(['products', 'categories']));
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return response()->json(null, 204);
    }

    /**
     * Return active promotions applicable to the current order context.
     * Includes scope=all promotions + product/category-scoped ones that match.
     */
    public function applicable(Request $request): AnonymousResourceCollection
    {
        $productIds = array_filter((array) $request->input('product_ids', []));
        $categoryIds = array_filter((array) $request->input('category_ids', []));
        $today = now()->toDateString();

        $promotions = Promotion::with(['products', 'categories'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->where(function ($q) use ($productIds, $categoryIds) {
                $q->where('scope', 'all')
                    ->orWhere(function ($q2) use ($productIds) {
                        $q2->where('scope', 'product')
                            ->whereHas('products', fn ($r) => $r->whereIn('products.id', $productIds ?: [0]));
                    })
                    ->orWhere(function ($q2) use ($categoryIds) {
                        $q2->where('scope', 'category')
                            ->whereHas('categories', fn ($r) => $r->whereIn('product_categories.id', $categoryIds ?: [0]));
                    });
            })
            ->get();

        return PromotionResource::collection($promotions);
    }

    private function validatePromotion(Request $request, ?Promotion $promotion = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::in(['buy_x_get_y', 'quantity_tier', 'order_discount', 'loyalty_point'])],
            'scope' => ['required', Rule::in(['product', 'category', 'all'])],
            'conditions' => ['required', 'array'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],
            'product_ids' => ['sometimes', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:product_categories,id'],
        ]);
    }

    private function syncRelations(Promotion $promotion, Request $request): void
    {
        if ($request->has('product_ids')) {
            $promotion->products()->sync($request->input('product_ids', []));
        }

        if ($request->has('category_ids')) {
            $promotion->categories()->sync($request->input('category_ids', []));
        }
    }
}
