<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TransactionType;
use App\Models\Inventory;
use App\Models\Organization;
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
            $order->load('items.product');

            $org = Organization::find(app('orgId'));
            if (! $org->setting('allow_negative_stock', false)) {
                $this->assertSufficientStock($order);
            }

            // Giải phóng reservation trước khi trừ tồn kho thực tế
            $this->releaseReservation($order);

            $order->update(['status' => OrderStatus::Confirmed]);

            // Snapshot avg_cost từ tồn kho vào từng item (WAC tại thời điểm xuất)
            $orgId = app('orgId');
            foreach ($order->items as $item) {
                $inventory = Inventory::where([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $item->warehouse_id,
                    'organization_id' => $orgId,
                ])->first();

                $avgCost = $inventory ? (float) $inventory->avg_cost : 0;
                $item->update(['cost_price' => $avgCost]);
            }

            // Reload sau khi update cost_price
            $order->load('items.product');

            // Xuất kho theo từng warehouse trong items
            $itemsByWarehouse = $order->items->groupBy('warehouse_id');

            foreach ($itemsByWarehouse as $warehouseId => $items) {
                $inventoryItems = $items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'quantity' => -(float) $item->quantity,
                    'unit_price' => (float) $item->cost_price,
                ])->all();

                $this->inventoryTransactionService->post(
                    type: TransactionType::SalesDelivery,
                    warehouseId: $warehouseId,
                    date: $order->order_date->toDateString(),
                    reference: $order,
                    items: $inventoryItems,
                    description: "Xuất hàng theo đơn {$order->order_number}",
                );
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

        foreach ($order->items as $item) {
            $inventory = Inventory::where([
                'product_id' => $item->product_id,
                'warehouse_id' => $item->warehouse_id,
                'organization_id' => $orgId,
            ])->first();

            // Tại thời điểm confirm, reservation của đơn này đã được tính vào reserved_quantity.
            // Kiểm tra tồn kho thực tế (quantity) phải đủ để xuất.
            $physical = $inventory ? (float) $inventory->quantity : 0;

            if ($physical < (float) $item->quantity) {
                $productName = $item->product->name ?? "SP #{$item->product_id}";
                $errors[] = "Sản phẩm \"{$productName}\" không đủ tồn kho (cần {$item->quantity}, hiện có {$physical}).";
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages(['inventory' => $errors]);
        }
    }

    private function releaseReservation(SalesOrder $order): void
    {
        $orgId = app('orgId');

        foreach ($order->items as $item) {
            Inventory::where([
                'product_id' => $item->product_id,
                'warehouse_id' => $item->warehouse_id,
                'organization_id' => $orgId,
            ])->decrement('reserved_quantity', (float) $item->quantity);
        }
    }
}
