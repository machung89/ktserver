<?php

namespace App\Services;

use App\Enums\PaymentType;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\TourPaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function create(array $data): Payment
    {
        // VND: làm tròn số tiền phiếu về đồng nguyên
        $data['amount'] = round((float) $data['amount']);
        $data['created_by'] ??= auth()->id();

        return DB::transaction(function () use ($data) {
            // Lock order và kiểm tra lại remaining bên trong transaction
            if (! empty($data['reference_type']) && ! empty($data['reference_id'])) {
                $order = $data['reference_type']::lockForUpdate()->find($data['reference_id']);
                if ($order) {
                    $totalAmount = (float) $order->total_amount;
                    $paidAmount = (float) $order->paid_amount;
                    $remaining = $totalAmount < 0
                        ? abs($totalAmount) - abs($paidAmount)
                        : $totalAmount - $paidAmount;
                    // VND: so sánh ở mức đồng nguyên, tránh chặn nhầm khi dữ liệu cũ còn đồng lẻ
                    if (round((float) $data['amount']) > round($remaining)) {
                        throw ValidationException::withMessages([
                            'amount' => [sprintf(
                                'Số tiền thanh toán (%s₫) vượt quá số tiền còn nợ (%s₫).',
                                number_format($data['amount'], 0, ',', '.'),
                                number_format($remaining, 0, ',', '.')
                            )],
                        ]);
                    }
                }
            }

            // Lock và kiểm tra lại tiền thu trước bên trong transaction
            $isApplyAdvance = ($data['is_advance'] ?? false) && ! empty($data['reference_id']);
            if ($isApplyAdvance && ! empty($data['company_id'])) {
                $available = $this->availableAdvanceLocked((int) $data['company_id'], (int) $data['organization_id']);
                if (round((float) $data['amount']) > round($available)) {
                    throw ValidationException::withMessages([
                        'amount' => [sprintf(
                            'Số tiền áp dụng (%s₫) vượt quá tiền thu trước còn lại (%s₫).',
                            number_format($data['amount'], 0, ',', '.'),
                            number_format($available, 0, ',', '.')
                        )],
                    ]);
                }
            }

            $payment = Payment::create([
                'payment_number' => $this->generatePaymentNumber($data['type']),
                ...$data,
            ]);

            $desc = $payment->description ?? $payment->payment_number;
            $amount = (float) $payment->amount;
            $account = $payment->account;

            // Áp tiền thu trước vào đơn hàng:
            //   Bút toán 131 tự bù trừ thông qua bút toán xác nhận đơn hàng (Nợ 131 / Có 511).
            //   Không tạo thêm bút toán riêng — chỉ cập nhật paid_amount trên đơn.
            $isApplyAdvance = $payment->is_advance && ! empty($payment->reference_id);

            if (! $isApplyAdvance) {
                $lines = $this->buildLines($payment, $account, $desc, $amount);

                if ($lines !== null) {
                    $this->journalEntryService->create(
                        description: $desc,
                        entryDate: $payment->payment_date->toDateString(),
                        reference: $payment,
                        lines: $lines,
                    );
                }
            }

            $payment->load(['company', 'account', 'toAccount', 'expenseAccount']);

            $ref = $payment->reference;
            if ($ref instanceof SalesOrder || $ref instanceof PurchaseOrder) {
                $ref->syncPaymentStatus();
            }

            return $payment;
        });
    }

    /**
     * Thu gộp: tạo MỘT phiếu thu cho cả cục tiền + MỘT bút toán (Nợ TM/TGNH / Có 131),
     * rồi phân bổ cho nhiều đơn qua payment_allocations. Dễ tra soát (1 dòng tiền = 1 phiếu).
     *
     * @param  array<string, mixed>  $data  company_id, account_id, payment_date, amount, description, organization_id
     * @param  array<int, array{sales_order_id: int, amount: float}>  $allocations
     */
    public function createReceiptWithAllocations(array $data, array $allocations): Payment
    {
        $data['amount'] = round((float) $data['amount']);

        return DB::transaction(function () use ($data, $allocations) {
            $payment = Payment::create([
                'payment_number' => $this->generatePaymentNumber(PaymentType::Receipt->value),
                'created_by' => auth()->id(),
                'group_id' => $data['group_id'] ?? null,
                'type' => PaymentType::Receipt->value,
                'company_id' => $data['company_id'],
                'account_id' => $data['account_id'],
                'payment_date' => $data['payment_date'],
                'amount' => $data['amount'],
                'description' => $data['description'] ?? 'Thu gộp nhiều đơn',
                'organization_id' => $data['organization_id'],
                'is_advance' => false,
                'status' => 'approved',
            ]);

            // Một bút toán cho cả cục: Nợ TM/TGNH / Có 131
            $desc = $payment->description ?? $payment->payment_number;
            $this->journalEntryService->create(
                description: $desc,
                entryDate: $payment->payment_date->toDateString(),
                reference: $payment,
                lines: [
                    ['account_code' => $payment->account->code, 'description' => $desc, 'debit' => (float) $payment->amount, 'credit' => 0],
                    ['account_code' => '131', 'description' => $desc, 'debit' => 0, 'credit' => (float) $payment->amount],
                ],
            );

            // Ghi phân bổ + đồng bộ trạng thái thanh toán từng đơn
            foreach ($allocations as $alloc) {
                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'sales_order_id' => $alloc['sales_order_id'],
                    'organization_id' => $data['organization_id'],
                    'amount' => $alloc['amount'],
                ]);
                SalesOrder::find($alloc['sales_order_id'])?->syncPaymentStatus();
            }

            return $payment->load(['company', 'account']);
        });
    }

    /**
     * Tạo phiếu chi ở trạng thái nháp — chưa sinh bút toán, chờ giám đốc duyệt.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDraft(array $data): Payment
    {
        $data['amount'] = round((float) $data['amount']);
        $data['created_by'] ??= auth()->id();

        return DB::transaction(function () use ($data) {
            return Payment::create([
                'payment_number' => $this->generatePaymentNumber($data['type']),
                ...$data,
                'status' => 'draft',
            ]);
        });
    }

    /**
     * Giám đốc duyệt phiếu chi nháp: chọn TK thanh toán → sinh bút toán → cập nhật paid_amount (tour).
     */
    public function approve(Payment $payment, int $accountId): Payment
    {
        return DB::transaction(function () use ($payment, $accountId) {
            $payment = Payment::lockForUpdate()->find($payment->id);

            if ($payment->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Chỉ có thể duyệt phiếu ở trạng thái nháp.']]);
            }

            $payment->update([
                'status' => 'approved',
                'account_id' => $accountId,
            ]);

            $payment->load(['account', 'expenseAccount']);

            $desc = $payment->description ?? $payment->payment_number;
            $amount = (float) $payment->amount;
            $lines = $this->buildLines($payment, $payment->account, $desc, $amount);

            if ($lines !== null) {
                $this->journalEntryService->create(
                    description: $desc,
                    entryDate: $payment->payment_date->toDateString(),
                    reference: $payment,
                    lines: $lines,
                );
            }

            // Nếu phiếu chi liên kết với lệnh thanh toán tour → cập nhật paid_amount dịch vụ
            if ($payment->reference_type === TourPaymentRequest::class) {
                $req = TourPaymentRequest::with('service')->find($payment->reference_id);
                if ($req?->service) {
                    $req->service->increment('paid_amount', $amount);
                }
            }

            return $payment->fresh(['company', 'account', 'expenseAccount']);
        });
    }

    public function availableSupplierAdvance(int $companyId, int $orgId): float
    {
        $paid = Payment::where('company_id', $companyId)
            ->where('organization_id', $orgId)
            ->where('type', PaymentType::Payment)
            ->where('is_advance', true)
            ->where('status', 'approved')
            ->whereNull('reference_id')
            ->sum('amount');

        $applied = Payment::where('company_id', $companyId)
            ->where('organization_id', $orgId)
            ->where('type', PaymentType::Payment)
            ->where('is_advance', true)
            ->whereNotNull('reference_id')
            ->sum('amount');

        return max(0, (float) $paid - (float) $applied);
    }

    private function availableSupplierAdvanceLocked(int $companyId, int $orgId): float
    {
        $base = Payment::where('company_id', $companyId)
            ->where('organization_id', $orgId)
            ->where('type', PaymentType::Payment)
            ->where('is_advance', true)
            ->where('status', 'approved')
            ->lockForUpdate();

        $paid = (clone $base)->whereNull('reference_id')->sum('amount');
        $applied = (clone $base)->whereNotNull('reference_id')->sum('amount');

        return max(0, (float) $paid - (float) $applied);
    }

    /**
     * Tiền khách có sẵn (credit) = tiền chưa phân bổ của MỌI phiếu thu chưa gắn đơn cụ thể
     * (mô hình unapplied cash): Σ phiếu thu (reference null).amount − Σ allocations − (dữ liệu cũ: is_advance đã áp có reference).
     */
    public function availableAdvance(int $companyId, int $orgId): float
    {
        return $this->computeAvailableAdvance($companyId, $orgId, false);
    }

    private function availableAdvanceLocked(int $companyId, int $orgId): float
    {
        return $this->computeAvailableAdvance($companyId, $orgId, true);
    }

    private function computeAvailableAdvance(int $companyId, int $orgId, bool $lock): float
    {
        $poolQuery = Payment::where('company_id', $companyId)
            ->where('organization_id', $orgId)
            ->where('type', PaymentType::Receipt)
            ->whereNull('reference_id');

        if ($lock) {
            $poolQuery->lockForUpdate();
        }

        $pool = (float) $poolQuery->sum('amount');

        // Đã phân bổ vào đơn từ các phiếu thu chưa gắn đơn
        $allocated = (float) PaymentAllocation::where('organization_id', $orgId)
            ->whereHas('payment', fn ($q) => $q->where('company_id', $companyId)
                ->where('type', PaymentType::Receipt)
                ->whereNull('reference_id'))
            ->sum('amount');

        // Tương thích ngược: phiếu is_advance kiểu cũ áp thẳng vào đơn (có reference_id)
        $legacyApplied = (float) Payment::where('company_id', $companyId)
            ->where('organization_id', $orgId)
            ->where('type', PaymentType::Receipt)
            ->where('is_advance', true)
            ->whereNotNull('reference_id')
            ->sum('amount');

        // Tiền đã hoàn trả lại cho khách (phiếu chi đối ứng 131) → giảm quỹ ứng còn lại
        $refunded = (float) Payment::where('company_id', $companyId)
            ->where('organization_id', $orgId)
            ->where('type', PaymentType::Payment)
            ->whereHas('expenseAccount', fn ($q) => $q->where('code', 'like', '131%'))
            ->sum('amount');

        return max(0, $pool - $allocated - $legacyApplied - $refunded);
    }

    /**
     * Áp tiền khách có sẵn (credit) vào đơn bán bằng phân bổ — KHÔNG tạo phiếu thu/bút toán mới.
     * Rút FIFO từ phần chưa phân bổ của mọi phiếu thu chưa gắn đơn (gồm cả thu trước lẫn dư từ thu gộp).
     */
    public function applyAdvanceToOrder(int $companyId, int $orderId, float $amount, int $orgId): void
    {
        DB::transaction(function () use ($companyId, $orderId, $amount, $orgId) {
            $left = round($amount);

            $receipts = Payment::where('company_id', $companyId)
                ->where('organization_id', $orgId)
                ->where('type', PaymentType::Receipt)
                ->whereNull('reference_id')
                ->orderBy('payment_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($receipts as $rc) {
                if ($left <= 0) {
                    break;
                }
                $used = (float) PaymentAllocation::where('payment_id', $rc->id)->sum('amount');
                $remaining = round((float) $rc->amount - $used);
                if ($remaining <= 0) {
                    continue;
                }
                $alloc = min($remaining, $left);
                PaymentAllocation::create([
                    'payment_id' => $rc->id,
                    'sales_order_id' => $orderId,
                    'organization_id' => $orgId,
                    'amount' => $alloc,
                ]);
                $left -= $alloc;
            }

            SalesOrder::find($orderId)?->syncPaymentStatus();
        });
    }

    /**
     * Bút toán theo loại phiếu:
     *   Thu trước (is_advance, no ref): Nợ 111/112 / Có 131
     *   Thu bình thường:               Nợ 111/112 / Có 131
     *   Chi phí kinh doanh:            Nợ 641/...  / Có 111/112
     *   Chi NCC (mặc định):            Nợ 331      / Có 111/112
     *   Chuyển tiền:                   Nợ to_account / Có account_id
     *
     * @return array<int, array{account_code: string, description: string, debit: float, credit: float}>|null
     */
    private function buildLines(Payment $payment, ?object $account, string $desc, float $amount): ?array
    {
        if ($payment->type === PaymentType::Transfer) {
            return [
                ['account_code' => $payment->toAccount->code, 'description' => $desc, 'debit' => $amount, 'credit' => 0],
                ['account_code' => $account->code, 'description' => $desc, 'debit' => 0, 'credit' => $amount],
            ];
        }

        if ($payment->type === PaymentType::Receipt) {
            if (! $account) {
                return null;
            }

            // Tài khoản đối ứng: mặc định 131 (thu của khách); cho phép chọn khác
            // (thu hoàn ứng NCC 331, thu hoàn tạm ứng NV 141, thu khác 711/511…).
            $counter = $payment->expense_account_id ? $payment->expenseAccount->code : '131';

            return [
                ['account_code' => $account->code, 'description' => $desc, 'debit' => $amount, 'credit' => 0],
                ['account_code' => $counter, 'description' => $desc, 'debit' => 0, 'credit' => $amount],
            ];
        }

        if ($payment->expense_account_id) {
            return [
                ['account_code' => $payment->expenseAccount->code, 'description' => $desc, 'debit' => $amount, 'credit' => 0],
                ['account_code' => $account->code, 'description' => $desc, 'debit' => 0, 'credit' => $amount],
            ];
        }

        return [
            ['account_code' => '331', 'description' => $desc, 'debit' => $amount, 'credit' => 0],
            ['account_code' => $account->code, 'description' => $desc, 'debit' => 0, 'credit' => $amount],
        ];
    }

    private function generatePaymentNumber(string $type): string
    {
        $prefix = match ($type) {
            'receipt' => 'PT',
            'transfer' => 'CT',
            default => 'PC',
        };
        $last = Payment::where('type', $type)->orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->payment_number, 2)) + 1 : 1;

        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
