<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TransactionType;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected InventoryTransactionService $inventoryTransactionService,
    ) {}

    public function confirm(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order->load('items.product');

            $order->update(['status' => OrderStatus::Confirmed]);

            // Nhập kho
            $inventoryItems = $order->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
            ])->all();

            $this->inventoryTransactionService->post(
                type: TransactionType::PurchaseReceipt,
                warehouseId: $order->warehouse_id,
                date: $order->order_date->toDateString(),
                reference: $order,
                items: $inventoryItems,
                description: "Nhập hàng theo phiếu {$order->order_number}",
            );

            // Bút toán: Nợ 156 / Có 331
            $lines = [];
            $lines[] = [
                'account_code' => '156',
                'description' => "Nhập hàng hóa - {$order->order_number}",
                'debit' => (float) $order->subtotal,
                'credit' => 0,
            ];

            if ((float) $order->tax_amount > 0) {
                $lines[] = [
                    'account_code' => '1331',
                    'description' => "Thuế GTGT đầu vào - {$order->order_number}",
                    'debit' => (float) $order->tax_amount,
                    'credit' => 0,
                ];
            }

            $lines[] = [
                'account_code' => '331',
                'description' => "Phải trả NCC - {$order->order_number}",
                'debit' => 0,
                'credit' => (float) $order->total_amount,
            ];

            $this->journalEntryService->create(
                description: "Nhập hàng hóa từ NCC - {$order->order_number}",
                entryDate: $order->order_date->toDateString(),
                reference: $order,
                lines: $lines,
            );

            return $order->fresh(['company', 'warehouse', 'items.product']);
        });
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        $order->update(['status' => OrderStatus::Cancelled]);

        return $order;
    }
}
