<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TransactionType;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected InventoryTransactionService $inventoryTransactionService,
    ) {}

    public function confirm(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = PurchaseOrder::lockForUpdate()->find($order->id);

            if ($order->status !== OrderStatus::Draft) {
                throw ValidationException::withMessages(['status' => ['Chỉ có thể xác nhận phiếu ở trạng thái nháp.']]);
            }

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

            // VND: làm tròn về đồng nguyên; 331 (Có) = 156 + 1331 (Nợ) để luôn cân tuyệt đối
            $goods = round((float) $order->subtotal);
            $vat = round((float) $order->tax_amount);

            // Bút toán: Nợ 156 (+ Nợ 1331 thuế) / Có 331
            $lines = [];
            $lines[] = [
                'account_code' => '156',
                'description' => "Nhập hàng hóa - {$order->order_number}",
                'debit' => $goods,
                'credit' => 0,
            ];

            if ($vat > 0) {
                $lines[] = [
                    'account_code' => '1331',
                    'description' => "Thuế GTGT đầu vào - {$order->order_number}",
                    'debit' => $vat,
                    'credit' => 0,
                ];
            }

            $lines[] = [
                'account_code' => '331',
                'description' => "Phải trả NCC - {$order->order_number}",
                'debit' => 0,
                'credit' => $goods + $vat,
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

    /**
     * Chuyển đơn nhập hoàn thành/xác nhận về nháp, đồng thời:
     * - Đảo bút toán kế toán (xóa journal entry liên kết)
     * - Đảo tồn kho (xóa inventory transaction + trừ lại số lượng / điều chỉnh avg_cost)
     * - Hủy các phiếu chi draft liên kết (không xóa phiếu đã được duyệt vì đã ghi sổ)
     *
     * Điều kiện: đơn chưa được thanh toán (paid_amount = 0) và không có phiếu chi đã duyệt.
     */
    public function revertToDraft(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = PurchaseOrder::with('items')->lockForUpdate()->find($order->id);

            if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Completed])) {
                throw ValidationException::withMessages(['status' => ['Chỉ có thể hoàn về nháp từ trạng thái đã xác nhận hoặc hoàn thành.']]);
            }

            // Chặn nếu đã có thanh toán được duyệt
            $approvedPayments = Payment::where('reference_type', PurchaseOrder::class)
                ->where('reference_id', $order->id)
                ->where('status', 'approved')
                ->count();

            if ($approvedPayments > 0) {
                throw ValidationException::withMessages(['status' => ['Không thể hoàn về nháp: đơn đã có phiếu thanh toán được duyệt. Vui lòng hủy phiếu thanh toán trước.']]);
            }

            // 1. Xóa bút toán liên kết
            $journals = JournalEntry::where('reference_type', PurchaseOrder::class)
                ->where('reference_id', $order->id)
                ->get();

            foreach ($journals as $journal) {
                $journal->lines()->delete();
                $journal->delete();
            }

            // 2. Đảo tồn kho
            $transactions = InventoryTransaction::where('reference_type', PurchaseOrder::class)
                ->where('reference_id', $order->id)
                ->with('items')
                ->get();

            foreach ($transactions as $transaction) {
                foreach ($transaction->items as $txItem) {
                    $this->inventoryTransactionService->updateInventoryBalance(
                        $transaction->warehouse_id,
                        $txItem->product_id,
                        -(float) $txItem->quantity, // đảo ngược: trừ lại số đã nhập
                        0,                           // avg_cost không điều chỉnh khi xuất
                    );
                }
                $transaction->items()->delete();
                $transaction->delete();
            }

            // 3. Hủy phiếu chi draft (chưa duyệt)
            Payment::where('reference_type', PurchaseOrder::class)
                ->where('reference_id', $order->id)
                ->where('status', 'draft')
                ->delete();

            // 4. Reset trạng thái + công nợ
            $order->update([
                'status' => OrderStatus::Draft,
                'paid_amount' => 0,
                'payment_status' => 'unpaid',
            ]);

            return $order->fresh(['company', 'warehouse', 'items.product']);
        });
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        $order->update(['status' => OrderStatus::Cancelled]);

        return $order;
    }
}
