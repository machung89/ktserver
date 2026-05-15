<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PromotionResource;
use App\Models\Promotion;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
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

    public function usageDetail(Promotion $promotion): JsonResponse
    {
        $orgId = $this->orgId();
        $notCancelled = ['draft', 'confirmed', 'shipping', 'completed'];

        if ($promotion->type === 'buy_x_get_y') {
            $giftProductId = $promotion->conditions['gift_product_id'] ?? null;
            if (! $giftProductId) {
                return response()->json(['data' => []]);
            }

            $rows = SalesOrderItem::with(['salesOrder.company'])
                ->where('product_id', $giftProductId)
                ->where('unit_price', 0)
                ->whereHas('salesOrder', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->whereIn('status', $notCancelled)
                )
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($item) => [
                    'order_id' => $item->salesOrder->id,
                    'order_number' => $item->salesOrder->order_number,
                    'order_date' => $item->salesOrder->order_date?->format('d/m/Y'),
                    'customer' => $item->salesOrder->company?->name,
                    'gift_qty' => (float) $item->quantity,
                    'status' => $item->salesOrder->getRawOriginal('status'),
                ]);

            return response()->json(['data' => $rows]);
        }

        if ($promotion->type === 'order_discount') {
            $rows = SalesOrder::with('company')
                ->where('organization_id', $orgId)
                ->where('promotion_id', $promotion->id)
                ->whereIn('status', $notCancelled)
                ->orderByDesc('order_date')
                ->get()
                ->map(fn ($o) => [
                    'order_id' => $o->id,
                    'order_number' => $o->order_number,
                    'order_date' => $o->order_date?->format('d/m/Y'),
                    'customer' => $o->company?->name,
                    'total_amount' => (float) $o->total_amount,
                    'discount_type' => $o->discount_type,
                    'discount_value' => (float) $o->discount_value,
                    'status' => $o->getRawOriginal('status'),
                ]);

            return response()->json(['data' => $rows]);
        }

        return response()->json(['data' => []]);
    }

    public function usageStats(): JsonResponse
    {
        $orgId = $this->orgId();
        $notCancelled = ['draft', 'confirmed', 'shipping', 'completed'];

        // buy_x_get_y: tổng quà đã tặng theo gift_product_id + unit_price = 0
        $giftProductIds = Promotion::where('organization_id', $orgId)
            ->where('type', 'buy_x_get_y')
            ->get()
            ->mapWithKeys(fn ($p) => [$p->id => $p->conditions['gift_product_id'] ?? null])
            ->filter();

        $giftStats = SalesOrderItem::whereIn('product_id', $giftProductIds->values())
            ->where('unit_price', 0)
            ->whereHas('salesOrder', fn ($q) => $q
                ->where('organization_id', $orgId)
                ->whereIn('status', $notCancelled)
            )
            ->selectRaw('product_id, SUM(quantity) as total_qty, COUNT(DISTINCT sales_order_id) as order_count')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // order_discount: đếm đơn hàng đã dùng promotion_id
        $orderStats = SalesOrder::where('organization_id', $orgId)
            ->whereNotNull('promotion_id')
            ->whereIn('status', $notCancelled)
            ->selectRaw('promotion_id, COUNT(*) as order_count')
            ->groupBy('promotion_id')
            ->get()
            ->keyBy('promotion_id');

        $promotions = Promotion::where('organization_id', $orgId)->get();

        $result = $promotions->map(function ($promo) use ($giftStats, $orderStats, $giftProductIds) {
            $stats = ['order_count' => 0, 'gift_qty' => 0];

            if ($promo->type === 'buy_x_get_y') {
                $giftProdId = $giftProductIds[$promo->id] ?? null;
                if ($giftProdId && isset($giftStats[$giftProdId])) {
                    $stats['gift_qty'] = (float) $giftStats[$giftProdId]->total_qty;
                    $stats['order_count'] = (int) $giftStats[$giftProdId]->order_count;
                }
            } elseif ($promo->type === 'order_discount') {
                if (isset($orderStats[$promo->id])) {
                    $stats['order_count'] = (int) $orderStats[$promo->id]->order_count;
                }
            }

            return ['id' => $promo->id, ...$stats];
        });

        return response()->json($result->keyBy('id'));
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
