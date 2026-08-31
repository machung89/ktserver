<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PushSubscription;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\StorePromotionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cửa hàng công khai — khách truy cập qua token (KHÔNG cần đăng nhập).
 * Xem khuyến mãi đang có + đăng ký nhận Web Push khi có KM mới.
 */
class PublicStoreController extends Controller
{
    private function resolveOrg(string $token): Organization
    {
        return Organization::where('public_token', $token)->where('is_active', true)->firstOrFail();
    }

    public function config(string $token): JsonResponse
    {
        $org = $this->resolveOrg($token);

        return response()->json([
            'shop_name' => $org->name,
            'vapid_public_key' => config('webpush.vapid.public_key'),
        ]);
    }

    public function promotions(string $token): JsonResponse
    {
        $org = $this->resolveOrg($token);
        $today = now()->toDateString();

        $promotions = Promotion::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->orderByDesc('id')
            ->get(['id', 'name', 'description', 'type', 'conditions', 'start_date', 'end_date']);

        // Gom tên sản phẩm được nhắc trong điều kiện để hiển thị chi tiết.
        $productIds = [];
        foreach ($promotions as $p) {
            foreach (($p->conditions['rules'] ?? []) as $r) {
                $productIds = array_merge($productIds, $r['buy_product_ids'] ?? [], $r['product_ids'] ?? []);
                if (! empty($r['gift_product_id'])) {
                    $productIds[] = $r['gift_product_id'];
                }
            }
        }
        $names = Product::withoutGlobalScopes()
            ->whereIn('id', array_values(array_unique($productIds)))
            ->pluck('name', 'id');

        $data = $promotions->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'type' => $p->type,
            'start_date' => $p->start_date?->toDateString(),
            'end_date' => $p->end_date?->toDateString(),
            'details' => $this->promotionDetails($p, $names),
        ]);

        return response()->json(['shop_name' => $org->name, 'promotions' => $data]);
    }

    /**
     * Diễn giải điều kiện khuyến mãi thành các dòng dễ đọc cho khách.
     *
     * @return list<string>
     */
    private function promotionDetails(Promotion $p, Collection $names): array
    {
        $c = $p->conditions ?? [];
        $nameOf = fn ($id) => $names[$id] ?? 'sản phẩm';
        $money = fn ($n) => number_format((float) $n, 0, ',', '.').'₫';
        $lines = [];

        if ($p->type === 'buy_x_get_y') {
            foreach ($c['rules'] ?? [] as $r) {
                $buy = ! empty($r['buy_product_ids'])
                    ? implode(', ', array_map($nameOf, $r['buy_product_ids']))
                    : 'sản phẩm bất kỳ';
                $lines[] = 'Mua '.$buy.' từ '.($r['min_qty'] ?? 1).' → tặng '.($r['gift_qty'] ?? 1).' '.$nameOf($r['gift_product_id'] ?? null);
            }
        } elseif ($p->type === 'buy_x_discount') {
            foreach ($c['rules'] ?? [] as $r) {
                $buy = ! empty($r['product_ids'])
                    ? implode(', ', array_map($nameOf, $r['product_ids']))
                    : 'sản phẩm bất kỳ';
                $lines[] = 'Mua '.$buy.' từ '.($r['min_qty'] ?? 1).' → giảm '.($r['discount_value'] ?? 0).'%';
            }
        } elseif ($p->type === 'quantity_tier') {
            foreach ($c['tiers'] ?? [] as $t) {
                $range = ! empty($t['max_qty']) ? $t['min_qty'].'–'.$t['max_qty'] : '≥'.($t['min_qty'] ?? 1);
                $disc = ($t['discount_type'] ?? 'percent') === 'percent'
                    ? '-'.($t['discount_value'] ?? 0).'%'
                    : '-'.$money($t['discount_value'] ?? 0);
                $lines[] = 'Mua '.$range.' → '.$disc;
            }
        } elseif ($p->type === 'order_discount') {
            $min = (float) ($c['min_order_value'] ?? 0);
            $disc = ($c['discount_type'] ?? 'percent') === 'percent'
                ? ($c['discount_value'] ?? 0).'%'
                : $money($c['discount_value'] ?? 0);
            $lines[] = ($min > 0 ? 'Đơn từ '.$money($min).' → ' : 'Mọi đơn hàng → ').'giảm '.$disc;
        } elseif ($p->type === 'loyalty_point') {
            if (! empty($c['point_rate'])) {
                $lines[] = 'Tích 1 điểm cho mỗi '.$money($c['point_rate']);
            }
            if (! empty($c['redeem_rate'])) {
                $lines[] = number_format((float) $c['redeem_rate'], 0, ',', '.').' điểm = 1.000₫';
            }
        }

        return $lines;
    }

    public function products(string $token): JsonResponse
    {
        $org = $this->resolveOrg($token);

        $products = Product::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            // Bán được = mọi SP trừ nguyên liệu (gồm cả product_type NULL của dữ liệu cũ).
            ->where(fn ($q) => $q->whereNull('product_type')->orWhere('product_type', '!=', 'ingredient'))
            ->with('category:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'unit' => $p->unit,
                'category_id' => $p->category_id,
                'category_name' => $p->category?->name,
                'image_url' => $p->image_path ? rtrim(config('app.url'), '/').'/storage/'.$p->image_path : null,
            ]);

        return response()->json([
            'shop_name' => $org->name,
            'enable_sales_tax' => (bool) $org->setting('enable_sales_tax', false),
            'default_tax_rate' => (float) $org->setting('default_tax_rate', 0),
            'products' => $products,
        ]);
    }

    /**
     * Khách tự đặt hàng (không đăng nhập) → tạo đơn bán nháp (draft) + giữ chỗ tồn,
     * đồng nhất với đơn bán do admin tạo. Thông tin khách lưu trong ghi chú.
     */
    public function placeOrder(string $token, Request $request): JsonResponse
    {
        $org = $this->resolveOrg($token);

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $orgId = $org->id;
        $taxEnabled = (bool) $org->setting('enable_sales_tax', false);
        $defaultTaxRate = (float) $org->setting('default_tax_rate', 0);

        $warehouse = Warehouse::withoutGlobalScopes()->where('organization_id', $orgId)->first();
        if (! $warehouse) {
            return response()->json(['message' => 'Cửa hàng chưa thiết lập kho hàng.'], 422);
        }

        $productIds = array_column($validated['items'], 'product_id');
        $products = Product::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($productIds as $pid) {
            if (! $products->has($pid)) {
                return response()->json(['message' => 'Sản phẩm không hợp lệ.'], 422);
            }
        }

        // Áp khuyến mãi (giảm dòng, quà tặng, giảm tổng đơn) — dùng chung engine với quote.
        $quote = (new StorePromotionEngine)->apply($orgId, $validated['items'], $taxEnabled, $defaultTaxRate);

        $order = DB::transaction(function () use ($validated, $orgId, $warehouse, $taxEnabled, $defaultTaxRate, $quote) {
            // SĐT trùng → gắn vào mã khách cũ; chưa có → tạo khách mới.
            $phone = trim($validated['customer_phone']);
            $company = Company::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('phone', $phone)
                ->whereIn('type', [CompanyType::Customer->value, CompanyType::Both->value])
                ->lockForUpdate()
                ->first();

            if (! $company) {
                $company = Company::create([
                    'organization_id' => $orgId,
                    'name' => $validated['customer_name'],
                    'phone' => $phone,
                    'address' => $validated['address'] ?? null,
                    'type' => CompanyType::Customer,
                    'code' => Company::generateCode($orgId, CompanyType::Customer),
                    'is_active' => true,
                ]);
            }

            $last = SalesOrder::withoutGlobalScopes()->orderByDesc('id')->lockForUpdate()->first();
            $seq = $last ? ((int) substr($last->order_number, 2)) + 1 : 1;

            $noteParts = ['[Đơn online] KH: '.$validated['customer_name'], 'SĐT: '.$validated['customer_phone']];
            if (! empty($validated['address'])) {
                $noteParts[] = 'Địa chỉ: '.$validated['address'];
            }
            if (! empty($validated['note'])) {
                $noteParts[] = 'Ghi chú: '.$validated['note'];
            }
            if (! empty($quote['applied'])) {
                $noteParts[] = 'KM: '.implode(', ', $quote['applied']);
            }

            $order = SalesOrder::create([
                'order_number' => 'BH'.str_pad($seq, 6, '0', STR_PAD_LEFT),
                'organization_id' => $orgId,
                'company_id' => $company->id,
                'status' => OrderStatus::Draft,
                'order_date' => now()->toDateString(),
                'promotion_id' => $quote['order_promotion_id'],
                'discount_type' => $quote['order_discount_type'],
                'discount_value' => $quote['order_discount_value'],
                'discount_amount' => $quote['order_discount_amount'],
                'subtotal' => $quote['subtotal'],
                'tax_amount' => $quote['tax_amount'],
                'total_amount' => $quote['total'],
                'notes' => implode(' | ', $noteParts),
            ]);

            foreach ($quote['lines'] as $line) {
                $qty = (int) $line['quantity'];
                $taxRate = ($taxEnabled && ! $line['is_gift']) ? $defaultTaxRate : 0;

                $order->items()->create([
                    'product_id' => $line['product_id'],
                    'warehouse_id' => $warehouse->id,
                    'quantity' => $qty,
                    'unit_price' => $line['unit_price'],
                    'discount_type' => $line['discount_type'],
                    'discount_value' => $line['discount_value'],
                    'cost_price' => 0,
                    'tax_rate' => $taxRate,
                    'amount' => $line['amount'],
                ]);

                $inv = Inventory::withoutGlobalScopes()
                    ->lockForUpdate()
                    ->firstOrCreate(
                        ['product_id' => $line['product_id'], 'warehouse_id' => $warehouse->id, 'organization_id' => $orgId],
                        ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                    );
                $inv->increment('reserved_quantity', $qty);
            }

            return $order->fresh();
        });

        return response()->json([
            'ok' => true,
            'order_number' => $order->order_number,
            'total_amount' => (float) $order->total_amount,
        ], 201);
    }

    /**
     * Tính thử khuyến mãi cho giỏ hàng (hiển thị ở màn Xác nhận đặt hàng) — không tạo đơn.
     */
    public function quote(string $token, Request $request): JsonResponse
    {
        $org = $this->resolveOrg($token);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $taxEnabled = (bool) $org->setting('enable_sales_tax', false);
        $defaultTaxRate = (float) $org->setting('default_tax_rate', 0);

        $quote = (new StorePromotionEngine)->apply($org->id, $validated['items'], $taxEnabled, $defaultTaxRate);

        return response()->json($quote);
    }

    public function subscribe(string $token, Request $request): JsonResponse
    {
        $org = $this->resolveOrg($token);

        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string'],
        ]);

        PushSubscription::updateOrCreate(
            ['organization_id' => $org->id, 'endpoint_hash' => hash('sha256', $validated['endpoint'])],
            [
                'endpoint' => $validated['endpoint'],
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['contentEncoding'] ?? 'aesgcm',
            ],
        );

        return response()->json(['ok' => true]);
    }
}
