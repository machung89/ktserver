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
use App\Models\RestaurantTable;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected InventoryTransactionService $inventoryTransactionService,
        protected ActivityLogService $activityLog,
    ) {}

    /**
     * Ghi nhật ký chuyển trạng thái đơn (dùng chung cho mọi đường: đơn lẻ, hàng loạt, phiếu xuất).
     */
    private function logStatus(SalesOrder $order, string $action, string $description): void
    {
        if (! Auth::id()) {
            return;
        }

        $this->activityLog->log(
            app('orgId'), Auth::id(), $action, $description,
            null, [], 'sales_order', $order->id
        );
    }

    /**
     * Giải phóng bàn nếu không còn đơn nào đang hoạt động trên bàn đó.
     */
    private function releaseTableIfIdle(SalesOrder $order): void
    {
        if (! $order->restaurant_table_id) {
            return;
        }

        $hasActive = SalesOrder::where('restaurant_table_id', $order->restaurant_table_id)
            ->where('id', '!=', $order->id)
            ->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])
            ->exists();

        if (! $hasActive) {
            RestaurantTable::where('id', $order->restaurant_table_id)
                ->update(['status' => 'available']);
        }
    }

    /**
     * Phân bổ chiết khấu cấp đơn cho từng dòng theo tỷ trọng doanh số,
     * lưu vào sales_order_items.order_discount_alloc. Σ alloc = discount_amount.
     */
    private function allocateOrderDiscount(SalesOrder $order): void
    {
        $discount = (float) $order->discount_amount;
        $subtotal = (float) $order->subtotal;

        if ($discount <= 0 || $subtotal <= 0) {
            $order->items()->update(['order_discount_alloc' => 0]);

            return;
        }

        $items = $order->items->values();
        $lastIdx = $items->count() - 1;
        $allocated = 0.0;

        foreach ($items as $idx => $item) {
            if ($idx === $lastIdx) {
                $alloc = round($discount - $allocated); // dồn phần dư vào dòng cuối (VND: đồng nguyên)
            } else {
                $alloc = round($discount * (float) $item->amount / $subtotal);
                $allocated += $alloc;
            }
            $item->update(['order_discount_alloc' => $alloc]);
        }
    }

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

            // Phân bổ chiết khấu cấp đơn cho từng dòng (snapshot doanh thu thuần).
            // Phần dư làm tròn dồn vào dòng cuối để Σ alloc = discount_amount tuyệt đối.
            $this->allocateOrderDiscount($order);

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

            $totalCost = round($order->items->sum(fn ($item) => $item->quantity * $item->cost_price));

            // VND: làm tròn về đồng nguyên tại đây để bút toán luôn cân, kể cả khi total/tax bị lẻ
            // (dữ liệu cũ hoặc nguồn import chưa làm tròn). 131 = 511 + 33311 tuyệt đối.
            $receivable = round((float) $order->total_amount);
            $vat = round((float) $order->tax_amount);
            $netRevenue = $receivable - $vat;

            // Bút toán: Nợ 131 / Có 511 + Nợ 632 / Có 156
            $lines = [
                [
                    'account_code' => '131',
                    'description' => "Phải thu KH - {$order->order_number}",
                    'debit' => $receivable,
                    'credit' => 0,
                ],
                [
                    'account_code' => '511',
                    'description' => "Doanh thu bán hàng - {$order->order_number}",
                    'debit' => 0,
                    'credit' => $netRevenue,
                ],
            ];

            if ($vat > 0) {
                $lines[] = [
                    'account_code' => '33311',
                    'description' => "Thuế GTGT đầu ra - {$order->order_number}",
                    'debit' => 0,
                    'credit' => $vat,
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

            $this->logStatus($order, 'sales_order_confirmed', "Xác nhận đơn bán {$order->order_number}");

            return $order->fresh(['company', 'items.product']);
        });
    }

    /**
     * Chuyển đơn đã xác nhận sang đang giao.
     */
    public function ship(SalesOrder $order): SalesOrder
    {
        if ($order->status !== OrderStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể giao đơn ở trạng thái đã xác nhận.']]);
        }

        $order->update(['status' => OrderStatus::Shipping]);
        $this->logStatus($order, 'sales_order_shipped', "Chuyển đơn bán {$order->order_number} sang đang giao");

        return $order->fresh();
    }

    /**
     * Hoàn thành đơn (từ đã xác nhận hoặc đang giao).
     */
    public function complete(SalesOrder $order): SalesOrder
    {
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Shipping])) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể hoàn thành đơn ở trạng thái đã xác nhận hoặc đang giao.']]);
        }

        $order->update(['status' => OrderStatus::Completed]);
        $this->releaseTableIfIdle($order);
        $this->logStatus($order, 'sales_order_completed', "Hoàn thành đơn bán {$order->order_number}");

        return $order->fresh();
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

            // SP combo/định mức: hoàn trả phải nhập lại THÀNH PHẦN, không phải SP tổng.
            $returnRecipes = Recipe::with('ingredients')
                ->whereIn('product_id', collect($data['items'])->pluck('product_id')->unique()->all())
                ->get()
                ->keyBy('product_id');

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
                $netAmount = round($qty * $netUnit);   // net dương của phần hoàn (VND: đồng nguyên)
                $amount = -$netAmount;                 // lưu âm
                $cost = round($qty * $costPrice);
                $lineTax = round($netAmount * $taxRate / 100);

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

                $recipe = $returnRecipes->get($productId);
                if ($recipe) {
                    // Combo/định mức: nhập lại từng thành phần theo công thức
                    $multiplier = (float) $qty / (float) $recipe->yield_quantity;
                    foreach ($recipe->ingredients as $ingredient) {
                        $ingQty = round((float) $ingredient->quantity * $multiplier, 4);
                        $ingAvg = (float) Inventory::where([
                            'product_id' => $ingredient->ingredient_id,
                            'warehouse_id' => $warehouseId,
                            'organization_id' => app('orgId'),
                        ])->value('avg_cost');

                        $inventoryByWarehouse[$warehouseId][] = [
                            'product_id' => $ingredient->ingredient_id,
                            'quantity' => $ingQty,
                            'unit_price' => $ingAvg,
                        ];
                    }
                } else {
                    $inventoryByWarehouse[$warehouseId][] = [
                        'product_id' => $productId,
                        'quantity' => $qty,
                        'unit_price' => $costPrice,
                    ];
                }
            }

            // Tính lại tổng tiền đơn hàng sau hoàn trả
            $order->load('items');
            $newSubtotal = round((float) $order->items->sum('amount'));
            $newTaxAmount = round((float) $order->items->sum(fn ($i) => $i->amount * $i->tax_rate / 100));
            $newTotal = $newSubtotal + $newTaxAmount;
            // Giá chuẩn & lời/lỗ NV theo số lượng ròng (dòng hoàn có qty âm nên tự trừ)
            $newStandardTotal = round((float) $order->items->sum(fn ($i) => (float) $i->quantity * (float) $i->standard_price));
            $order->update([
                'subtotal' => $newSubtotal,
                'tax_amount' => $newTaxAmount,
                'total_amount' => $newTotal,
                'standard_total' => $newStandardTotal,
                'employee_profit' => $newStandardTotal > 0 ? $newTotal - $newStandardTotal : 0,
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
                $totalGross = round($totalRevenue + $totalTax);
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
                    // Nhập lại đúng giá vốn đã ghi lúc xuất (txItem->unit_price)
                    // → stock_value & avg_cost khớp với việc đảo bút toán Có 156.
                    $this->inventoryTransactionService->updateInventoryBalance(
                        $transaction->warehouse_id,
                        $txItem->product_id,
                        -(float) $txItem->quantity,
                        (float) $txItem->unit_price,
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

            $this->logStatus($order, 'sales_order_reverted', "Đảo đơn bán {$order->order_number} về nháp — đã hủy bút toán và tồn kho");

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
                        // Nhập lại đúng giá vốn đã ghi lúc xuất (txItem->unit_price) để
                        // stock_value & avg_cost khớp với việc đảo bút toán Có 156.
                        $this->inventoryTransactionService->updateInventoryBalance(
                            $transaction->warehouse_id,
                            $txItem->product_id,
                            -(float) $txItem->quantity,
                            (float) $txItem->unit_price,
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
            $this->releaseTableIfIdle($order);
            $this->logStatus($order, 'sales_order_cancelled', "Hủy đơn bán {$order->order_number}");

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
