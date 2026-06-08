<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
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

                    // Ưu tiên avg_cost của tồn kho; nếu = 0 (chưa nhập lần nào hoặc tồn âm)
                    // thì fallback về cost_price của sản phẩm để tránh giá vốn ảo = 0.
                    $avgCost = $inventory ? (float) $inventory->avg_cost : 0;
                    if ($avgCost <= 0) {
                        $avgCost = (float) ($item->product->cost_price ?? 0);
                    }
                    $item->update(['cost_price' => $avgCost]);
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
            $totalTax = 0;
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
                $taxRate = $original ? (float) $original->tax_rate : 0;

                // Thành tiền hoàn theo tỷ lệ trên net amount gốc (đã trừ chiết khấu),
                // không dùng unit_price gốc để tránh bỏ sót chiết khấu.
                $origQty = $original ? (float) $original->quantity : 0;
                $netUnit = $origQty != 0 ? (float) $original->amount / $origQty : $unitPrice;
                $netAmount = round($qty * $netUnit, 2);   // net dương của phần hoàn
                $amount = -$netAmount;                     // lưu âm
                $cost = round($qty * $costPrice, 2);
                $lineTax = round($netAmount * $taxRate / 100, 2);

                $totalRevenue += $netAmount;
                $totalTax += $lineTax;
                $totalCost += $cost;

                $order->items()->create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => -$qty,
                    'unit_price' => $unitPrice,
                    'cost_price' => $costPrice,
                    'standard_price' => $original ? (float) $original->standard_price : 0,
                    'discount_type' => $original?->discount_type,
                    'discount_value' => $original ? (float) $original->discount_value : 0,
                    'tax_rate' => $taxRate,
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
            $newTotal = round($newSubtotal + $newTaxAmount, 2);
            // Giá chuẩn & lời/lỗ NV theo số lượng ròng (dòng hoàn có qty âm nên tự trừ)
            $newStandardTotal = round((float) $order->items->sum(fn ($i) => (float) $i->quantity * (float) $i->standard_price), 2);
            $order->update([
                'subtotal' => round($newSubtotal, 2),
                'tax_amount' => round($newTaxAmount, 2),
                'total_amount' => $newTotal,
                'standard_total' => $newStandardTotal,
                'employee_profit' => $newStandardTotal > 0 ? round($newTotal - $newStandardTotal, 2) : 0,
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
                $totalGross = round($totalRevenue + $totalTax, 2);
                // Đảo chiều bút toán bán hàng: Nợ 511 (+ Nợ 33311 thuế) / Có 131 + Nợ 156 / Có 632
                $lines = [
                    ['account_code' => '511', 'description' => $desc, 'debit' => $totalRevenue, 'credit' => 0],
                ];

                if ($totalTax > 0) {
                    $lines[] = ['account_code' => '33311', 'description' => "Thuế GTGT hàng trả lại - {$order->order_number}", 'debit' => $totalTax, 'credit' => 0];
                }

                $lines[] = ['account_code' => '131', 'description' => $desc, 'debit' => 0, 'credit' => $totalGross];

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

    /**
     * Đảo đơn bán đã xác nhận/giao/hoàn thành về nháp:
     * - Đảo tồn kho (cộng lại số đã xuất, giữ avg_cost)
     * - Xóa bút toán liên quan
     * - Đặt lại reserved_quantity (đơn nháp giữ chỗ tồn kho)
     *
     * Chặn nếu đơn đã có thanh toán hoặc đã hoàn trả hàng.
     */
    public function revertToDraft(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order) {
            $order = SalesOrder::with('items')->lockForUpdate()->find($order->id);

            if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Shipping, OrderStatus::Completed])) {
                throw ValidationException::withMessages(['status' => ['Chỉ có thể đảo về nháp từ đơn đã xác nhận, đang giao hoặc hoàn thành.']]);
            }

            // Chặn khi đã có thanh toán (dương cho đơn thường, âm cho đơn hoàn tiền) — dùng abs để đúng cả 2 chiều
            if (abs((float) $order->paid_amount) > 0.001) {
                throw ValidationException::withMessages(['status' => ['Không thể đảo về nháp: đơn đã có thanh toán. Vui lòng xóa phiếu thu/chi liên quan trước.']]);
            }

            if ($order->items->contains(fn ($i) => $i->is_return)) {
                throw ValidationException::withMessages(['status' => ['Không thể đảo về nháp: đơn đã có hàng hoàn trả.']]);
            }

            // 1. Đảo tồn kho
            $transactions = InventoryTransaction::where('reference_type', SalesOrder::class)
                ->where('reference_id', $order->id)
                ->with('items')
                ->get();

            foreach ($transactions as $transaction) {
                foreach ($transaction->items as $txItem) {
                    $currentAvgCost = (float) Inventory::where([
                        'product_id' => $txItem->product_id,
                        'warehouse_id' => $transaction->warehouse_id,
                        'organization_id' => app('orgId'),
                    ])->value('avg_cost');

                    $this->inventoryTransactionService->updateInventoryBalance(
                        $transaction->warehouse_id,
                        $txItem->product_id,
                        -(float) $txItem->quantity,
                        $currentAvgCost,
                    );
                }
                $transaction->items()->delete();
                $transaction->delete();
            }

            // 2. Xóa bút toán
            $journals = JournalEntry::where('reference_type', SalesOrder::class)
                ->where('reference_id', $order->id)
                ->get();

            foreach ($journals as $journal) {
                $journal->lines()->delete();
                $journal->delete();
            }

            // 3. Đặt lại giữ chỗ tồn kho cho đơn nháp
            $this->reserveStock($order);

            // 4. Trở về nháp
            $order->update([
                'status' => OrderStatus::Draft,
                'payment_status' => PaymentStatus::Unpaid,
                'paid_amount' => 0,
            ]);

            return $order->fresh(['company', 'items.product']);
        });
    }

    public function cancel(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order) {
            if ($order->status === OrderStatus::Draft) {
                // Đơn nháp: giải phóng reserved, không cần rollback kho/bút toán
                $order->loadMissing('items');
                $this->releaseReservation($order);
            } elseif (in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Shipping, OrderStatus::Completed])) {
                // Đơn đã xác nhận/giao/hoàn thành: đảo tồn kho và bút toán

                // Giải phóng reserved nếu còn sót (Shipping có thể vẫn còn reserved)
                $order->loadMissing('items');
                $this->releaseReservation($order);

                $transactions = InventoryTransaction::where('reference_type', SalesOrder::class)
                    ->where('reference_id', $order->id)
                    ->with('items')
                    ->get();

                foreach ($transactions as $transaction) {
                    foreach ($transaction->items as $txItem) {
                        // qty trong transaction là số âm (xuất kho), đảo lại = cộng vào kho.
                        // Truyền avg_cost hiện tại để WAC không bị sai khi "nhập lại".
                        $currentAvgCost = (float) Inventory::where([
                            'product_id' => $txItem->product_id,
                            'warehouse_id' => $transaction->warehouse_id,
                            'organization_id' => app('orgId'),
                        ])->value('avg_cost');

                        $this->inventoryTransactionService->updateInventoryBalance(
                            $transaction->warehouse_id,
                            $txItem->product_id,
                            -(float) $txItem->quantity,   // âm → dương = nhập lại
                            $currentAvgCost,              // giữ avg_cost ổn định
                        );
                    }
                    $transaction->items()->delete();
                    $transaction->delete();
                }

                $journals = JournalEntry::where('reference_type', SalesOrder::class)
                    ->where('reference_id', $order->id)
                    ->get();

                foreach ($journals as $journal) {
                    $journal->lines()->delete();
                    $journal->delete();
                }
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
        $this->adjustReservation($order, -1);
    }

    private function reserveStock(SalesOrder $order): void
    {
        $this->adjustReservation($order, 1);
    }

    /**
     * Tăng/giảm reserved_quantity theo từng item (và nguyên liệu nếu có công thức).
     * $sign = 1 để giữ chỗ, -1 để giải phóng.
     */
    private function adjustReservation(SalesOrder $order, int $sign): void
    {
        $orgId = app('orgId');

        $productIds = $order->items->pluck('product_id')->unique()->all();
        $recipes = Recipe::with('ingredients')
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        foreach ($order->items as $item) {
            // Item trả hàng (số lượng âm) là nhập lại kho, không giữ chỗ tồn.
            if ($item->is_return || (float) $item->quantity <= 0) {
                continue;
            }

            $recipe = $recipes->get($item->product_id);

            if ($recipe) {
                $multiplier = (float) $item->quantity / (float) $recipe->yield_quantity;
                foreach ($recipe->ingredients as $ingredient) {
                    $ingQty = round((float) $ingredient->quantity * $multiplier, 4);
                    Inventory::ensureExists($ingredient->ingredient_id, $item->warehouse_id, $orgId);
                    Inventory::where([
                        'product_id' => $ingredient->ingredient_id,
                        'warehouse_id' => $item->warehouse_id,
                        'organization_id' => $orgId,
                    ])->increment('reserved_quantity', $sign * $ingQty);
                }
            } else {
                Inventory::ensureExists($item->product_id, $item->warehouse_id, $orgId);
                Inventory::where([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $item->warehouse_id,
                    'organization_id' => $orgId,
                ])->increment('reserved_quantity', $sign * (float) $item->quantity);
            }
        }
    }
}
