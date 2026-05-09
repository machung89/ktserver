<?php

namespace App\Services;

use App\Enums\PaymentType;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function create(array $data): Payment
    {
        return DB::transaction(function () use ($data) {
            $payment = Payment::create([
                'payment_number' => $this->generatePaymentNumber($data['type']),
                ...$data,
            ]);

            // Thu: Nợ 111/112 / Có 131
            // Chi NCC: Nợ 331 / Có 111/112
            // Chi phí (có expense_account_id): Nợ 641/642/334/... / Có 111/112
            $account = $payment->account;
            $desc = $payment->description ?? $payment->payment_number;
            $amount = (float) $payment->amount;

            if ($payment->type === PaymentType::Receipt) {
                $lines = [
                    ['account_code' => $account->code, 'description' => $desc, 'debit' => $amount, 'credit' => 0],
                    ['account_code' => '131', 'description' => $desc, 'debit' => 0, 'credit' => $amount],
                ];
            } elseif ($payment->expense_account_id) {
                $lines = [
                    ['account_code' => $payment->expenseAccount->code, 'description' => $desc, 'debit' => $amount, 'credit' => 0],
                    ['account_code' => $account->code, 'description' => $desc, 'debit' => 0, 'credit' => $amount],
                ];
            } else {
                $lines = [
                    ['account_code' => '331', 'description' => $desc, 'debit' => $amount, 'credit' => 0],
                    ['account_code' => $account->code, 'description' => $desc, 'debit' => 0, 'credit' => $amount],
                ];
            }

            $this->journalEntryService->create(
                description: $desc,
                entryDate: $payment->payment_date->toDateString(),
                reference: $payment,
                lines: $lines,
            );

            $payment->load(['company', 'account', 'expenseAccount']);

            // Sync payment status on linked order
            $ref = $payment->reference;
            if ($ref instanceof SalesOrder || $ref instanceof PurchaseOrder) {
                $ref->syncPaymentStatus();
            }

            return $payment;
        });
    }

    private function generatePaymentNumber(string $type): string
    {
        $prefix = $type === 'receipt' ? 'PT' : 'PC';
        $last = Payment::where('type', $type)->orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->payment_number, 3)) + 1 : 1;

        return $prefix.'-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
