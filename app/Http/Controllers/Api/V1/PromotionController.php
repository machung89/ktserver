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
use Illuminate\Support\Facades\DB;
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

        $data = collect($validated)->except(['product_ids', 'category_ids'])->toArray();
        if (in_array($data['type'] ?? '', ['buy_x_get_y', 'buy_x_discount']) && ! empty($data['conditions']['rules'])) {
            $data['scope'] = 'product';
        }
        $data['scope'] ??= 'product';

        $promotion = Promotion::create(array_merge($data, ['organization_id' => $this->orgId()]));

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

        $data = collect($validated)->except(['product_ids', 'category_ids'])->toArray();
        if (in_array($data['type'] ?? $promotion->type, ['buy_x_get_y', 'buy_x_discount']) && ! empty($data['conditions']['rules'])) {
            $data['scope'] = 'product';
        }

        $promotion->update($data);

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
            $giftProductIds = $this->extractGiftProductIds($promotion);
            if (empty($giftProductIds)) {
                return response()->json(['data' => []]);
            }

            $rows = SalesOrderItem::with(['salesOrder.company', 'product'])
                ->whereIn('product_id', $giftProductIds)
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
                    'gift_product' => $item->product?->name,
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

        $promotions = Promotion::where('organization_id', $orgId)->get();

        // buy_x_get_y: collect all gift_product_ids (support multi-rule format)
        $promoGiftMap = $promotions
            ->where('type', 'buy_x_get_y')
            ->mapWithKeys(fn ($p) => [$p->id => $this->extractGiftProductIds($p)])
            ->filter(fn ($ids) => ! empty($ids));

        $allGiftIds = $promoGiftMap->flatten()->unique()->values()->all();

        $giftStats = ! empty($allGiftIds)
            ? SalesOrderItem::whereIn('product_id', $allGiftIds)
                ->where('unit_price', 0)
                ->whereHas('salesOrder', fn ($q) => $q
                    ->where('organization_id', $orgId)
                    ->whereIn('status', $notCancelled)
                )
                ->selectRaw('product_id, SUM(quantity) as total_qty, COUNT(DISTINCT sales_order_id) as order_count')
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id')
            : collect();

        // order_discount: đếm đơn hàng đã dùng promotion_id
        $orderStats = SalesOrder::where('organization_id', $orgId)
            ->whereNotNull('promotion_id')
            ->whereIn('status', $notCancelled)
            ->selectRaw('promotion_id, COUNT(*) as order_count')
            ->groupBy('promotion_id')
            ->get()
            ->keyBy('promotion_id');

        $result = $promotions->map(function ($promo) use ($giftStats, $orderStats, $promoGiftMap) {
            $stats = ['order_count' => 0, 'gift_qty' => 0];

            if ($promo->type === 'buy_x_get_y') {
                $giftIds = $promoGiftMap[$promo->id] ?? [];
                foreach ($giftIds as $gid) {
                    if (isset($giftStats[$gid])) {
                        $stats['gift_qty'] += (float) $giftStats[$gid]->total_qty;
                        $stats['order_count'] = max($stats['order_count'], (int) $giftStats[$gid]->order_count);
                    }
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

    public function recalculateOrders(Request $request, Promotion $promotion): JsonResponse
    {
        $validated = $request->validate([
            'chia_gia' => ['required', 'array'],
            'chia_gia.*' => ['numeric', 'min:0'],
        ]);

        $chiaGia = $validated['chia_gia'];
        $productIds = array_map('intval', array_keys($chiaGia));
        $updatedCount = 0;

        DB::transaction(function () use ($promotion, $chiaGia, $productIds, &$updatedCount) {
            $orders = SalesOrder::with('items')
                ->where('organization_id', $this->orgId())
                ->whereNotIn('status', ['cancelled'])
                ->when($promotion->start_date, fn ($q) => $q->whereDate('order_date', '>=', $promotion->start_date))
                ->when($promotion->end_date, fn ($q) => $q->whereDate('order_date', '<=', $promotion->end_date))
                ->get();

            foreach ($orders as $order) {
                $hasUpdate = false;
                foreach ($order->items as $item) {
                    if (in_array($item->product_id, $productIds)) {
                        $item->update(['standard_price' => $chiaGia[(string) $item->product_id]]);
                        $hasUpdate = true;
                    }
                }
                if ($hasUpdate) {
                    $order->load('items');
                    $standardTotal = $order->items->sum(
                        fn ($i) => (float) $i->standard_price > 0 ? (float) $i->quantity * (float) $i->standard_price : 0
                    );
                    $order->update([
                        'standard_total' => $standardTotal,
                        'employee_profit' => $standardTotal > 0 ? (float) $order->total_amount - $standardTotal : 0,
                    ]);
                    $updatedCount++;
                }
            }
        });

        return response()->json(['updated_orders' => $updatedCount]);
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
            'type' => ['required', Rule::in(['buy_x_get_y', 'quantity_tier', 'order_discount', 'loyalty_point', 'buy_x_discount'])],
            'scope' => ['sometimes', Rule::in(['product', 'category', 'all'])],
            'conditions' => ['required', 'array'],
            'conditions.rules' => ['sometimes', 'array', 'min:1'],
            'conditions.rules.*.buy_product_ids' => ['sometimes', 'array'],
            'conditions.rules.*.buy_product_ids.*' => ['integer', 'exists:products,id'],
            'conditions.rules.*.product_ids' => ['sometimes', 'array'],
            'conditions.rules.*.product_ids.*' => ['integer', 'exists:products,id'],
            'conditions.rules.*.min_qty' => ['nullable', 'numeric', 'min:1'],
            'conditions.rules.*.gift_product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'conditions.rules.*.gift_qty' => ['sometimes', 'numeric', 'min:1'],
            'conditions.rules.*.discount_value' => ['sometimes', 'numeric', 'min:0', 'max:100'],
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
        // For buy_x_get_y multi-rule: auto-derive product_ids from all rules
        if ($promotion->type === 'buy_x_get_y' && ! empty($promotion->conditions['rules'])) {
            $allIds = collect($promotion->conditions['rules'])
                ->flatMap(fn ($r) => $r['buy_product_ids'] ?? [])
                ->unique()
                ->filter()
                ->values()
                ->all();
            $promotion->products()->sync($allIds);

            return;
        }

        // For buy_x_discount: auto-derive product_ids from all rules
        if ($promotion->type === 'buy_x_discount' && ! empty($promotion->conditions['rules'])) {
            $allIds = collect($promotion->conditions['rules'])
                ->flatMap(fn ($r) => $r['product_ids'] ?? [])
                ->unique()
                ->filter()
                ->values()
                ->all();
            $promotion->products()->sync($allIds);

            return;
        }

        if ($request->has('product_ids')) {
            $promotion->products()->sync($request->input('product_ids', []));
        }

        if ($request->has('category_ids')) {
            $promotion->categories()->sync($request->input('category_ids', []));
        }
    }

    /** @return int[] */
    private function extractGiftProductIds(Promotion $promotion): array
    {
        $conditions = $promotion->conditions ?? [];

        // Multi-rule format
        if (! empty($conditions['rules'])) {
            return collect($conditions['rules'])
                ->pluck('gift_product_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        // Legacy single-rule format
        $id = $conditions['gift_product_id'] ?? null;

        return $id ? [(int) $id] : [];
    }
}
