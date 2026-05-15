<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TransactionType;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected InventoryTransactionService $inventoryTransactionService,
    ) {}

    public function confirm(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order) {
            $order = SalesOrder::lockForUpdate()->find($order->id);

            if ($order->status !== OrderStatus::Draft) {
                throw ValidationException::withMessages(['status' => ['Chỉ có thể xác nhận đơn ở trạng thái nháp.']]);
            }

            $order->load('items.product');

            $org = Organization::find(app('orgId'));
            if (! $org->setting('allow_negative_stock', false)) {
                $this->assertSufficientStock($order);
            }

            // Giải phóng reservation trước khi trừ tồn kho thực tế
            $this->releaseReservation($order);

            $order->update(['status' => OrderStatus::Confirmed]);

            $orgId = app('orgId');

            // Load công thức TRƯỚC khi snapshot cost (cần để tính giá vốn từ nguyên liệu)
            $productIds = $order->items->pluck('product_id')->unique()->all();
            $recipes = Recipe::with('ingredients')
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy('product_id');

            // Snapshot cost_price vào từng item tại thời điểm xuất (WAC):
            // - Sản phẩm có công thức → giá vốn = Σ(SL NL × giá TB NL) / SL thành phẩm
            // - Sản phẩm thường       → giá vốn = giá TB xuất kho thành phẩm
            foreach ($order->items as $item) {
                $recipe = $recipes->get($item->product_id);

                if ($recipe) {
                    $multiplier = (float) $item->quantity / (float) $recipe->yield_quantity;
                    $totalIngCost = 0;

                    foreach ($recipe->ingredients as $ingredient) {
                        $ingInventory = Inventory::where([
                            'product_id' => $ingredient->ingredient_id,
                            'warehouse_id' => $item->warehouse_id,
                            'organization_id' => $orgId,
                        ])->first();

                        $totalIngCost += (float) $ingredient->quantity * $multiplier
                            * ($ingInventory ? (float) $ingInventory->avg_cost : 0);
                    }

                    $costPerUnit = $item->quantity > 0 ? $totalIngCost / (float) $item->quantity : 0;
                    $item->update(['cost_price' => round($costPerUnit, 4)]);
                } else {
                    $inventory = Inventory::where([
                        'product_id' => $item->product_id,
                        'warehouse_id' => $item->warehouse_id,
                        'organization_id' => $orgId,
                    ])->first();

                    $item->update(['cost_price' => $inventory ? (float) $inventory->avg_cost : 0]);
                }
            }

            // Reload sau khi update cost_price
            $order->load('items.product');

            // Sản phẩm không có công thức: xuất kho trực tiếp (SalesDelivery)
            $nonRecipeItems = $order->items->filter(fn ($item) => ! $recipes->has($item->product_id));
            if ($nonRecipeItems->isNotEmpty()) {
                foreach ($nonRecipeItems->groupBy('warehouse_id') as $warehouseId => $items) {
                    $this->inventoryTransactionService->post(
                        type: TransactionType::SalesDelivery,
                        warehouseId: $warehouseId,
                        date: $order->order_date->toDateString(),
                        reference: $order,
                        items: $items->map(fn ($item) => [
                            'product_id' => $item->product_id,
                            'quantity' => -(float) $item->quantity,
                            'unit_price' => (float) $item->cost_price,
                        ])->all(),
                        description: "Xuất hàng theo đơn {$order->order_number}",
                    );
                }
            }

            // Sản phẩm có công thức: xuất nguyên liệu (RecipeConsumption), không trừ kho món ăn
            if ($recipes->isNotEmpty()) {
                $ingByWarehouse = [];

                foreach ($order->items as $item) {
                    $recipe = $recipes->get($item->product_id);
                    if (! $recipe) {
                        continue;
                    }

                    $multiplier = (float) $item->quantity / (float) $recipe->yield_quantity;

                    foreach ($recipe->ingredients as $ingredient) {
                        $ingId = $ingredient->ingredient_id;
                        $ingQty = round((float) $ingredient->quantity * $multiplier, 4);

                        $ingInventory = Inventory::where([
                            'product_id' => $ingId,
                            'warehouse_id' => $item->warehouse_id,
                            'organization_id' => $orgId,
                        ])->first();

                        $ingByWarehouse[$item->warehouse_id][] = [
                            'product_id' => $ingId,
                            'quantity' => -$ingQty,
                            'unit_price' => $ingInventory ? (float) $ingInventory->avg_cost : 0,
                        ];
                    }
                }

                foreach ($ingByWarehouse as $warehouseId => $ingItems) {
                    $this->inventoryTransactionService->post(
                        type: TransactionType::RecipeConsumption,
                        warehouseId: $warehouseId,
                        date: $order->order_date->toDateString(),
                        reference: $order,
                        items: $ingItems,
                        description: "Xuất NL theo công thức - {$order->order_number}",
                    );
                }
            }

            $totalCost = $order->items->sum(fn ($item) => $item->quantity * $item->cost_price);

            // Bút toán: Nợ 131 / Có 511 + Nợ 632 / Có 156
            $lines = [
                [
                    'account_code' => '131',
                    'description' => "Phải thu KH - {$order->order_number}",
                    'debit' => (float) $order->total_amount,
                    'credit' => 0,
                ],
                [
                    'account_code' => '511',
                    'description' => "Doanh thu bán hàng - {$order->order_number}",
                    'debit' => 0,
                    'credit' => (float) $order->subtotal,
                ],
            ];

            if ((float) $order->tax_amount > 0) {
                $lines[] = [
                    'account_code' => '33311',
                    'description' => "Thuế GTGT đầu ra - {$order->order_number}",
                    'debit' => 0,
                    'credit' => (float) $order->tax_amount,
                ];
            }

            $lines[] = [
                'account_code' => '632',
                'description' => "Giá vốn hàng bán - {$order->order_number}",
                'debit' => (float) $totalCost,
                'credit' => 0,
            ];
            $lines[] = [
                'account_code' => '156',
                'description' => "Xuất kho hàng bán - {$order->order_number}",
                'debit' => 0,
                'credit' => (float) $totalCost,
            ];

            $this->journalEntryService->create(
                description: "Bán hàng cho KH - {$order->order_number}",
                entryDate: $order->order_date->toDateString(),
                reference: $order,
                lines: $lines,
            );

            return $order->fresh(['company', 'items.product']);
        });
    }

    /**
     * @param  array{return_date: string, notes: ?string, items: array<array{product_id: int, warehouse_id: int, quantity: float}>}  $data
     */
    public function createReturnItems(SalesOrder $order, array $data): SalesOrder
    {
        $allowedStatuses = [OrderStatus::Confirmed, OrderStatus::Shipping];
        if (! in_array($order->status, $allowedStatuses)) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể hoàn trả đơn đã xác nhận hoặc đang giao.']]);
        }

        return DB::transaction(function () use ($order, $data) {
            $order->loadMissing('items.product');

            $positiveItems = $order->items->filter(fn ($i) => ! $i->is_return);
            $returnDate = $data['return_date'];
            $returnNote = $data['notes'] ?? null;
            $totalRevenue = 0;
            $totalCost = 0;
            $inventoryByWarehouse = [];

            foreach ($data['items'] as $input) {
                $productId = (int) $input['product_id'];
                $warehouseId = (int) $input['warehouse_id'];
                $qty = (float) $input['quantity'];

                // Find original item to get prices
                $original = $positiveItems->first(fn ($i) => $i->product_id === $productId && $i->warehouse_id === $warehouseId);
                $unitPrice = $original ? (float) $original->unit_price : 0;
                $costPrice = $original ? (float) $original->cost_price : 0;
                $amount = round(-$qty * $unitPrice, 2);
                $cost = round($qty * $costPrice, 2);
                $totalRevenue += abs($amount);
                $totalCost += $cost;

                $order->items()->create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => -$qty,
                    'unit_price' => $unitPrice,
                    'cost_price' => $costPrice,
                    'tax_rate' => $original ? $original->tax_rate : 0,
                    'amount' => $amount,
                    'is_return' => true,
                    'return_date' => $returnDate,
                    'return_note' => $returnNote,
                ]);

                $inventoryByWarehouse[$warehouseId][] = [
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'unit_price' => $costPrice,
                ];
            }

            // Tính lại tổng tiền đơn hàng sau hoàn trả
            $order->load('items');
            $newSubtotal = (float) $order->items->sum('amount');
            $newTaxAmount = (float) $order->items->sum(fn ($i) => $i->amount * $i->tax_rate / 100);
            $order->update([
                'subtotal' => round($newSubtotal, 2),
                'tax_amount' => round($newTaxAmount, 2),
                'total_amount' => round($newSubtotal + $newTaxAmount, 2),
            ]);

            // Đồng bộ trạng thái thanh toán theo tổng tiền mới
            $order->syncPaymentStatus();

            // Nhập kho trả lại theo warehouse
            foreach ($inventoryByWarehouse as $warehouseId => $items) {
                $this->inventoryTransactionService->post(
                    type: TransactionType::SalesReturn,
                    warehouseId: $warehouseId,
                    date: $returnDate,
                    reference: $order,
                    items: $items,
                    description: "Hàng bán bị trả lại - {$order->order_number}",
                );
            }

            if ($totalRevenue > 0) {
                $desc = "Hàng bán bị trả lại - {$order->order_number}";
                $lines = [
                    ['account_code' => '5212', 'description' => $desc, 'debit' => $totalRevenue, 'credit' => 0],
                    ['account_code' => '131', 'description' => $desc, 'debit' => 0, 'credit' => $totalRevenue],
                ];

                if ($totalCost > 0) {
                    $lines[] = ['account_code' => '156', 'description' => $desc, 'debit' => $totalCost, 'credit' => 0];
                    $lines[] = ['account_code' => '632', 'description' => $desc, 'debit' => 0, 'credit' => $totalCost];
                }

                $this->journalEntryService->create(
                    description: $desc,
                    entryDate: $returnDate,
                    reference: $order,
                    lines: $lines,
                );
            }

            // Sau khi hoàn trả, đơn xác nhận/đang giao → hoàn thành (phần còn lại đã giao)
            if (in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Shipping])) {
                $order->update(['status' => OrderStatus::Completed]);
            }

            $order->syncPaymentStatus();

            return $order->fresh(['company', 'items.product', 'items.warehouse']);
        });
    }

    public function cancel(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status === OrderStatus::Draft) {
                $order->loadMissing('items');
                $this->releaseReservation($order);
            }

            $order->update(['status' => OrderStatus::Cancelled]);

            return $order;
        });
    }

    private function assertSufficientStock(SalesOrder $order): void
    {
        $orgId = app('orgId');
        $errors = [];

        $productIds = $order->items->pluck('product_id')->unique()->all();
        $recipes = Recipe::with('ingredients.ingredient:id,name')
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        // Sản phẩm không có công thức: kiểm tra tồn kho trực tiếp
        foreach ($order->items->filter(fn ($item) => ! $recipes->has($item->product_id)) as $item) {
            $inventory = Inventory::where([
                'product_id' => $item->product_id,
                'warehouse_id' => $item->warehouse_id,
                'organization_id' => $orgId,
            ])->lockForUpdate()->first();

            $physical = $inventory ? (float) $inventory->quantity : 0;

            if ($physical < (float) $item->quantity) {
                $productName = $item->product->name ?? "SP #{$item->product_id}";
                $errors[] = "Sản phẩm \"{$productName}\" không đủ tồn kho (cần {$item->quantity}, hiện có {$physical}).";
            }
        }

        // Kiểm tra nguyên liệu theo công thức

        if ($recipes->isNotEmpty()) {
            // Tổng hợp yêu cầu nguyên liệu theo (warehouseId, ingredientId)
            $ingRequirements = [];

            foreach ($order->items as $item) {
                $recipe = $recipes->get($item->product_id);
                if (! $recipe) {
                    continue;
                }

                $multiplier = (float) $item->quantity / (float) $recipe->yield_quantity;

                foreach ($recipe->ingredients as $ingredient) {
                    $key = "{$item->warehouse_id}:{$ingredient->ingredient_id}";
                    $ingRequirements[$key]['warehouse_id'] = $item->warehouse_id;
                    $ingRequirements[$key]['ingredient_id'] = $ingredient->ingredient_id;
                    $ingRequirements[$key]['name'] = $ingredient->ingredient?->name ?? "NL #{$ingredient->ingredient_id}";
                    $ingRequirements[$key]['qty'] = ($ingRequirements[$key]['qty'] ?? 0) + ((float) $ingredient->quantity * $multiplier);
                }
            }

            foreach ($ingRequirements as $req) {
                $inventory = Inventory::where([
                    'product_id' => $req['ingredient_id'],
                    'warehouse_id' => $req['warehouse_id'],
                    'organization_id' => $orgId,
                ])->lockForUpdate()->first();

                $physical = $inventory ? (float) $inventory->quantity : 0;

                if ($physical < $req['qty']) {
                    $errors[] = "Nguyên liệu \"{$req['name']}\" không đủ tồn kho (cần ".round($req['qty'], 4).", hiện có {$physical}).";
                }
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages(['inventory' => $errors]);
        }
    }

    private function releaseReservation(SalesOrder $order): void
    {
        $orgId = app('orgId');

        $productIds = $order->items->pluck('product_id')->unique()->all();
        $recipes = Recipe::with('ingredients')
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        foreach ($order->items as $item) {
            $recipe = $recipes->get($item->product_id);

            if ($recipe) {
                $multiplier = (float) $item->quantity / (float) $recipe->yield_quantity;
                foreach ($recipe->ingredients as $ingredient) {
                    $ingQty = round((float) $ingredient->quantity * $multiplier, 4);
                    Inventory::where([
                        'product_id' => $ingredient->ingredient_id,
                        'warehouse_id' => $item->warehouse_id,
                        'organization_id' => $orgId,
                    ])->decrement('reserved_quantity', $ingQty);
                }
            } else {
                Inventory::where([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $item->warehouse_id,
                    'organization_id' => $orgId,
                ])->decrement('reserved_quantity', (float) $item->quantity);
            }
        }
    }
}
