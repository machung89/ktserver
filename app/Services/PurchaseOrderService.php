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

            $this->postCogsVarianceForBackorder($order);

            return $order->fresh(['company', 'warehouse', 'items.product']);
        });
    }

    /**
     * Kết chuyển chênh lệch giá vốn cho hàng "bán trước - nhập sau".
     *
     * Sau khi nhập kho, SP nào đã đủ tồn (≥ 0) mà giá vốn tạm tính lúc bán khác giá nhập thật
     * sẽ còn một khoản chênh nằm trong giá trị tồn (stock_value ≠ quantity × avg_cost).
     * Ghi 632/156 cho phần chênh đó rồi đưa stock_value về đúng WAC, để TK 156 không bị lệch.
     */
    private function postCogsVarianceForBackorder(PurchaseOrder $order): void
    {
        $orgId = app('orgId');
        $variance = 0.0;
        $processed = [];

        foreach ($order->items as $item) {
            if (in_array($item->product_id, $processed, true)) {
                continue;
            }
            $processed[] = $item->product_id;

            $inventory = Inventory::where([
                'product_id' => $item->product_id,
                'warehouse_id' => $order->warehouse_id,
                'organization_id' => $orgId,
            ])->lockForUpdate()->first();

            // Còn âm → chưa lấp đủ, để lần nhập sau mới chốt
            if (! $inventory || (float) $inventory->quantity < 0) {
                continue;
            }

            $delta = $this->inventoryTransactionService->cogsVariance($inventory);
            if (abs($delta) < 1) {
                continue; // bỏ qua nhiễu làm tròn dưới 1 đồng
            }

            // Đã kết chuyển phần chênh → đưa giá trị tồn về đúng WAC
            $inventory->update(['stock_value' => round((float) $inventory->quantity * (float) $inventory->avg_cost, 2)]);
            $variance += $delta;
        }

        $variance = round($variance, 2);
        if (abs($variance) < 1) {
            return;
        }

        $desc = "Kết chuyển chênh lệch giá vốn (bán trước - nhập sau) - {$order->order_number}";

        // Giá nhập thật > giá vốn tạm → ghi tăng giá vốn (Nợ 632 / Có 156); ngược lại đảo chiều.
        $lines = $variance > 0
            ? [
                ['account_code' => '632', 'description' => $desc, 'debit' => $variance, 'credit' => 0],
                ['account_code' => '156', 'description' => $desc, 'debit' => 0, 'credit' => $variance],
            ]
            : [
                ['account_code' => '156', 'description' => $desc, 'debit' => -$variance, 'credit' => 0],
                ['account_code' => '632', 'description' => $desc, 'debit' => 0, 'credit' => -$variance],
            ];

        $this->journalEntryService->create(
            description: $desc,
            entryDate: $order->order_date->toDateString(),
            reference: $order,
            lines: $lines,
        );
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
        // Chặn nếu đơn đã phát sinh kết chuyển chênh lệch giá vốn (bán trước - nhập sau):
        // việc này đã điều chỉnh WAC và giá trị tồn của các SP khác → không thể đảo sạch tự động.
        $hasCogsVariance = JournalEntry::where('reference_type', PurchaseOrder::class)
            ->where('reference_id', $order->id)
            ->whereHas('lines.account', fn ($q) => $q->where('code', '632'))
            ->exists();

        if ($hasCogsVariance) {
            throw ValidationException::withMessages(['status' => ['Không thể hoàn về nháp: đơn đã kết chuyển chênh lệch giá vốn hàng bán trước - nhập sau. Vui lòng điều chỉnh thủ công.']]);
        }

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
                        -(float) $txItem->quantity,     // đảo ngược: trừ lại số đã nhập
                        (float) $txItem->unit_price,    // trừ đúng giá trị đã ghi vào stock_value (avg_cost không đổi khi xuất)
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
