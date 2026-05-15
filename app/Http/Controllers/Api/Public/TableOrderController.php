<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TableOrderController extends Controller
{
    public function info(string $token): JsonResponse
    {
        $table = RestaurantTable::withoutGlobalScopes()
            ->where('qr_token', $token)
            ->firstOrFail();

        $orgId = $table->organization_id;
        $org = Organization::find($orgId);

        $products = Product::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where('product_type', 'product')
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
            ]);

        $currentOrder = SalesOrder::withoutGlobalScopes()
            ->where('restaurant_table_id', $table->id)
            ->where('organization_id', $orgId)
            ->where('status', 'draft')
            ->with('items.product:id,name,unit')
            ->latest()
            ->first();

        return response()->json([
            'table' => [
                'id' => $table->id,
                'name' => $table->name,
                'capacity' => $table->capacity,
                'status' => $table->status,
            ],
            'org' => [
                'name' => $org?->name,
                'enable_sales_tax' => (bool) $org?->setting('enable_sales_tax', false),
                'default_tax_rate' => (float) $org?->setting('default_tax_rate', 0),
            ],
            'products' => $products,
            'current_order' => $currentOrder ? $this->formatOrder($currentOrder) : null,
        ]);
    }

    public function placeOrder(Request $request, string $token): JsonResponse
    {
        $table = RestaurantTable::withoutGlobalScopes()
            ->where('qr_token', $token)
            ->firstOrFail();

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $orgId = $table->organization_id;
        $org = Organization::find($orgId);
        $taxEnabled = (bool) $org?->setting('enable_sales_tax', false);
        $defaultTaxRate = (float) $org?->setting('default_tax_rate', 0);

        $warehouse = Warehouse::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->first();

        if (! $warehouse) {
            return response()->json(['message' => 'Nhà hàng chưa thiết lập kho hàng.'], 422);
        }

        $productIds = array_column($validated['items'], 'product_id');
        $products = Product::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($productIds as $pid) {
            if (! $products->has($pid)) {
                return response()->json(['message' => 'Sản phẩm không hợp lệ.'], 422);
            }
        }

        $order = DB::transaction(function () use ($table, $validated, $orgId, $warehouse, $products, $taxEnabled, $defaultTaxRate) {
            $order = SalesOrder::withoutGlobalScopes()
                ->where('restaurant_table_id', $table->id)
                ->where('organization_id', $orgId)
                ->where('status', 'draft')
                ->with('items')
                ->lockForUpdate()
                ->latest()
                ->first();

            if (! $order) {
                $last = SalesOrder::withoutGlobalScopes()->orderByDesc('id')->lockForUpdate()->first();
                $seq = $last ? ((int) substr($last->order_number, 2)) + 1 : 1;

                $order = SalesOrder::create([
                    'order_number' => 'BH'.str_pad($seq, 6, '0', STR_PAD_LEFT),
                    'organization_id' => $orgId,
                    'restaurant_table_id' => $table->id,
                    'status' => OrderStatus::Draft,
                    'order_date' => now()->toDateString(),
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'total_amount' => 0,
                ]);

                DB::table('restaurant_tables')
                    ->where('id', $table->id)
                    ->update(['status' => 'occupied']);

                $order->load('items');
            }

            $existingByProductId = $order->items->keyBy('product_id');

            foreach ($validated['items'] as $newItem) {
                $product = $products->get($newItem['product_id']);
                $qty = (int) $newItem['quantity'];
                $price = (float) $product->price;
                $taxRate = $taxEnabled ? $defaultTaxRate : 0;

                $existing = $existingByProductId->get($newItem['product_id']);

                if ($existing) {
                    $newQty = (float) $existing->quantity + $qty;
                    $existing->update([
                        'quantity' => $newQty,
                        'amount' => round($newQty * $price, 2),
                    ]);
                    Inventory::withoutGlobalScopes()
                        ->where(['product_id' => $product->id, 'warehouse_id' => $existing->warehouse_id, 'organization_id' => $orgId])
                        ->increment('reserved_quantity', $qty);
                } else {
                    $amount = round($qty * $price, 2);
                    $order->items()->create([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'discount_type' => null,
                        'discount_value' => 0,
                        'cost_price' => 0,
                        'tax_rate' => $taxRate,
                        'amount' => $amount,
                    ]);
                    $inv = Inventory::withoutGlobalScopes()
                        ->lockForUpdate()
                        ->firstOrCreate(
                            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'organization_id' => $orgId],
                            ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                        );
                    $inv->increment('reserved_quantity', $qty);
                }
            }

            $order->load('items');
            $subtotal = $order->items->sum(fn ($i) => (float) $i->amount);
            $taxAmount = $order->items->sum(fn ($i) => (float) $i->amount * (float) $i->tax_rate / 100);

            $order->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $subtotal + $taxAmount,
            ]);

            return $order->fresh(['items.product:id,name,unit']);
        });

        return response()->json(['order' => $this->formatOrder($order)], 201);
    }

    /** @param SalesOrder $order */
    private function formatOrder($order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'total_amount' => (float) $order->total_amount,
            'items' => $order->items
                ->filter(fn ($i) => ! $i->is_return)
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'product_name' => $i->product?->name,
                    'quantity' => (float) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'amount' => (float) $i->amount,
                    'is_served' => (bool) $i->is_served,
                ])
                ->values(),
        ];
    }
}
