<?php

namespace App\Services;

use App\Enums\PaymentType;
use App\Models\Payment;
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
        return DB::transaction(function () use ($data) {
            // Lock order và kiểm tra lại remaining bên trong transaction
            if (! empty($data['reference_type']) && ! empty($data['reference_id'])) {
                $order = $data['reference_type']::lockForUpdate()->find($data['reference_id']);
                if ($order) {
                    $remaining = (float) $order->total_amount - (float) $order->paid_amount;
                    if ((float) $data['amount'] > $remaining + 0.01) {
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
                if ((float) $data['amount'] > $available + 0.01) {
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

            $payment->load(['company', 'account', 'expenseAccount']);

            $ref = $payment->reference;
            if ($ref instanceof SalesOrder || $ref instanceof PurchaseOrder) {
                $ref->syncPaymentStatus();
            }

            return $payment;
        });
    }

    /**
     * Tạo phiếu chi ở trạng thái nháp — chưa sinh bút toán, chờ giám đốc duyệt.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDraft(array $data): Payment
    {
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

    public function availableAdvance(int $companyId, int $orgId): float
    {
        $received = Payment::where('company_id', $companyId)
            ->where('organization_id', $orgId)
            ->where('type', PaymentType::Receipt)
            ->where('is_advance', true)
            ->whereNull('reference_id')
            ->sum('amount');

        $applied = Payment::where('company_id', $companyId)
            ->where('organization_id', $orgId)
            ->where('type', PaymentType::Receipt)
            ->where('is_advance', true)
            ->whereNotNull('reference_id')
            ->sum('amount');

        return max(0, (float) $received - (float) $applied);
    }

    private function availableAdvanceLocked(int $companyId, int $orgId): float
    {
        $base = Payment::where('company_id', $companyId)
            ->where('organization_id', $orgId)
            ->where('type', PaymentType::Receipt)
            ->where('is_advance', true)
            ->lockForUpdate();

        $received = (clone $base)->whereNull('reference_id')->sum('amount');
        $applied = (clone $base)->whereNotNull('reference_id')->sum('amount');

        return max(0, (float) $received - (float) $applied);
    }

    /**
     * Bút toán theo loại phiếu:
     *   Thu trước (is_advance, no ref): Nợ 111/112 / Có 131
     *   Thu bình thường:               Nợ 111/112 / Có 131
     *   Chi phí kinh doanh:            Nợ 641/...  / Có 111/112
     *   Chi NCC (mặc định):            Nợ 331      / Có 111/112
     *
     * @return array<int, array{account_code: string, description: string, debit: float, credit: float}>|null
     */
    private function buildLines(Payment $payment, ?object $account, string $desc, float $amount): ?array
    {
        if ($payment->type === PaymentType::Receipt) {
            if (! $account) {
                return null;
            }

            return [
                ['account_code' => $account->code, 'description' => $desc, 'debit' => $amount, 'credit' => 0],
                ['account_code' => '131', 'description' => $desc, 'debit' => 0, 'credit' => $amount],
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
        $prefix = $type === 'receipt' ? 'PT' : 'PC';
        $last = Payment::where('type', $type)->orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->payment_number, 2)) + 1 : 1;

        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
