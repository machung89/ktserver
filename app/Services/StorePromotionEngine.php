<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Support\Collection;

/**
 * Áp khuyến mãi cho giỏ hàng khách đặt online (khớp công thức trang bán hàng admin).
 *
 * Hỗ trợ: buy_x_discount + quantity_tier (giảm theo dòng), buy_x_get_y (thêm dòng quà tặng),
 * order_discount (giảm trên tổng đơn). Giá lấy từ DB (không tin giá client gửi lên).
 */
class StorePromotionEngine
{
    /**
     * @param  array<int, array{product_id:int, quantity:int}>  $cart
     * @return array{lines: list<array<string, mixed>>, subtotal: float, tax_amount: float, order_discount_type: ?string, order_discount_value: float, order_discount_amount: float, order_promotion_id: ?int, order_promotion_name: ?string, total: float, applied: list<string>}
     */
    public function apply(int $orgId, array $cart, bool $taxEnabled = false, float $defaultTaxRate = 0): array
    {
        $cart = collect($cart)
            ->map(fn ($r) => ['product_id' => (int) $r['product_id'], 'quantity' => (int) $r['quantity']])
            ->filter(fn ($r) => $r['product_id'] > 0 && $r['quantity'] > 0)
            ->values();

        $productIds = $cart->pluck('product_id')->all();

        /** @var Collection<int, Product> $products */
        $products = Product::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $today = now()->toDateString();
        $promotions = Promotion::withoutGlobalScopes()
            ->with(['products:id', 'categories:id'])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->get();

        $applied = [];
        $lines = [];

        // 1) Dòng hàng thường + giảm giá theo dòng (buy_x_discount, quantity_tier)
        foreach ($cart as $row) {
            $product = $products->get($row['product_id']);
            if (! $product) {
                continue;
            }
            $qty = $row['quantity'];
            $price = (float) $product->price;
            $base = $qty * $price;

            [$discType, $discValue, $discAmount, $promoName] = $this->bestLineDiscount($product, $qty, $base, $promotions);
            if ($promoName) {
                $applied[$promoName] = true;
            }

            $lines[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'unit' => $product->unit,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount_type' => $discType,
                'discount_value' => $discValue,
                'discount_amount' => round($discAmount),
                'amount' => round($base - $discAmount),
                'is_gift' => false,
                'promo' => $promoName,
            ];
        }

        // 2) Quà tặng (buy_x_get_y)
        foreach ($promotions->where('type', 'buy_x_get_y') as $promo) {
            foreach (($promo->conditions['rules'] ?? []) as $rule) {
                $giftQty = $this->giftQtyForRule($rule, $cart);
                if ($giftQty <= 0) {
                    continue;
                }
                $gift = $products->get($rule['gift_product_id'] ?? 0)
                    ?? Product::withoutGlobalScopes()->where('organization_id', $orgId)->find($rule['gift_product_id'] ?? 0);
                if (! $gift) {
                    continue;
                }
                $applied[$promo->name] = true;
                $lines[] = [
                    'product_id' => $gift->id,
                    'name' => '🎁 '.$gift->name,
                    'unit' => $gift->unit,
                    'quantity' => $giftQty,
                    'unit_price' => 0,
                    'discount_type' => null,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'amount' => 0,
                    'is_gift' => true,
                    'promo' => $promo->name,
                ];
            }
        }

        // 3) Tổng phụ + thuế
        $subtotal = 0;
        $taxAmount = 0;
        foreach ($lines as $line) {
            $subtotal += $line['amount'];
            if ($taxEnabled && $defaultTaxRate > 0 && ! $line['is_gift']) {
                $taxAmount += round($line['amount'] * $defaultTaxRate / 100);
            }
        }

        // 4) Giảm trên tổng đơn (order_discount)
        $orderDiscType = null;
        $orderDiscValue = 0.0;
        $orderDiscAmount = 0.0;
        $orderPromoId = null;
        $orderPromoName = null;
        $orderPromo = $promotions->firstWhere('type', 'order_discount');
        if ($orderPromo) {
            $c = $orderPromo->conditions ?? [];
            $minOrder = (float) ($c['min_order_value'] ?? 0);
            if ($subtotal >= $minOrder) {
                $orderDiscType = ($c['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
                $orderDiscValue = (float) ($c['discount_value'] ?? 0);
                $orderDiscAmount = $orderDiscType === 'percent'
                    ? round($subtotal * $orderDiscValue / 100)
                    : min(round($orderDiscValue), $subtotal);
                if ($orderDiscAmount > 0) {
                    $orderPromoId = $orderPromo->id;
                    $orderPromoName = $orderPromo->name;
                    $applied[$orderPromo->name] = true;
                }
            }
        }

        $total = $subtotal + $taxAmount - $orderDiscAmount;

        return [
            'lines' => $lines,
            'subtotal' => (float) $subtotal,
            'tax_amount' => (float) $taxAmount,
            'order_discount_type' => $orderDiscType,
            'order_discount_value' => $orderDiscValue,
            'order_discount_amount' => (float) $orderDiscAmount,
            'order_promotion_id' => $orderPromoId,
            'order_promotion_name' => $orderPromoName,
            'total' => (float) $total,
            'applied' => array_keys($applied),
        ];
    }

    /**
     * Giảm giá theo dòng tốt nhất từ buy_x_discount + quantity_tier.
     *
     * @return array{0: ?string, 1: float, 2: float, 3: ?string}
     */
    private function bestLineDiscount(Product $product, int $qty, float $base, Collection $promotions): array
    {
        $best = ['type' => null, 'value' => 0.0, 'amount' => 0.0, 'name' => null];

        $consider = function (?string $type, float $value, string $name) use ($base, &$best) {
            if (! $type || $value <= 0) {
                return;
            }
            $amount = $type === 'percent' ? $base * $value / 100 : min($value, $base);
            if ($amount > $best['amount']) {
                $best = ['type' => $type, 'value' => $value, 'amount' => $amount, 'name' => $name];
            }
        };

        // buy_x_discount: rule.product_ids chứa SP và qty >= min_qty → giảm %
        foreach ($promotions->where('type', 'buy_x_discount') as $promo) {
            foreach (($promo->conditions['rules'] ?? []) as $rule) {
                $ids = $rule['product_ids'] ?? [];
                if (in_array($product->id, $ids) && $qty >= (int) ($rule['min_qty'] ?? 1)) {
                    $consider('percent', (float) ($rule['discount_value'] ?? 0), $promo->name);
                }
            }
        }

        // quantity_tier: theo scope product/category, tìm bậc phù hợp
        foreach ($promotions->where('type', 'quantity_tier') as $promo) {
            $matchScope = match ($promo->scope) {
                'product' => $promo->products->pluck('id')->contains($product->id),
                'category' => $promo->categories->pluck('id')->contains($product->category_id),
                default => false,
            };
            if (! $matchScope) {
                continue;
            }
            foreach (($promo->conditions['tiers'] ?? []) as $tier) {
                $min = (int) ($tier['min_qty'] ?? 0);
                $max = $tier['max_qty'] ?? null;
                if ($qty >= $min && ($max === null || $max === '' || $qty <= (int) $max)) {
                    $consider($tier['discount_type'] ?? 'percent', (float) ($tier['discount_value'] ?? 0), $promo->name);
                }
            }
        }

        return [$best['type'], $best['value'], $best['amount'], $best['name']];
    }

    /**
     * Số lượng quà tặng cho 1 rule buy_x_get_y = floor(tổng SL mua / min_qty) × gift_qty.
     *
     * @param  array<string, mixed>  $rule
     */
    private function giftQtyForRule(array $rule, Collection $cart): int
    {
        $buyIds = $rule['buy_product_ids'] ?? [];
        if (empty($buyIds)) {
            return 0;
        }
        $minQty = (int) ($rule['min_qty'] ?? 1) ?: 1;
        $giftQty = (int) ($rule['gift_qty'] ?? 1) ?: 1;
        $totalQty = $cart->whereIn('product_id', $buyIds)->sum('quantity');

        return intdiv((int) $totalQty, $minQty) * $giftQty;
    }
}
