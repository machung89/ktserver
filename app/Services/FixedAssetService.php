<?php

namespace App\Services;

use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FixedAssetService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function create(array $data): FixedAsset
    {
        return DB::transaction(function () use ($data) {
            $asset = FixedAsset::create([
                'code' => $this->generateCode(),
                ...$data,
            ]);

            // Bút toán mua tài sản: Nợ 211 / Có 111/112/331
            if ($asset->account_id) {
                $account = $asset->account;
                $desc = "Mua tài sản: {$asset->name}";
                $cost = round((float) $asset->cost); // VND: đồng nguyên
                $this->journalEntryService->create(
                    description: $desc,
                    entryDate: $asset->purchase_date->toDateString(),
                    reference: $asset,
                    lines: [
                        ['account_code' => $asset->asset_account_code, 'description' => $desc, 'debit' => $cost, 'credit' => 0],
                        ['account_code' => $account->code, 'description' => $desc, 'debit' => 0, 'credit' => $cost],
                    ],
                );
            }

            return $asset->load(['company', 'account', 'expenseAccount']);
        });
    }

    public function postDepreciation(FixedAsset $asset, int $year): FixedAssetDepreciation
    {
        return DB::transaction(function () use ($asset, $year) {
            $startYear = (int) $asset->depreciation_start_date->format('Y');
            $endYear = $startYear + $asset->useful_life_years - 1;

            if ($year < $startYear || $year > $endYear) {
                throw ValidationException::withMessages([
                    'year' => ["Năm {$year} nằm ngoài thời gian khấu hao ({$startYear}–{$endYear})."],
                ]);
            }

            $depreciation = FixedAssetDepreciation::firstOrCreate(
                ['fixed_asset_id' => $asset->id, 'year' => $year],
                ['organization_id' => $asset->organization_id, 'amount' => $asset->depreciationForYear($year)],
            );

            if ($depreciation->journal_entry_id) {
                throw ValidationException::withMessages([
                    'year' => ["Khấu hao năm {$year} đã được ghi nhận (bút toán #{$depreciation->journal_entry_id})."],
                ]);
            }

            if (! $asset->expense_account_id) {
                throw ValidationException::withMessages([
                    'expense_account_id' => ['Chưa chọn tài khoản chi phí khấu hao cho tài sản này.'],
                ]);
            }

            $expCode = $asset->expenseAccount->code;
            $desc = "Khấu hao {$asset->name} năm {$year}";

            $journalEntry = $this->journalEntryService->create(
                description: $desc,
                entryDate: "{$year}-12-31",
                reference: $depreciation,
                lines: [
                    ['account_code' => $expCode, 'description' => $desc, 'debit' => (float) $depreciation->amount, 'credit' => 0],
                    ['account_code' => $asset->accumulated_depreciation_account_code, 'description' => $desc, 'debit' => 0, 'credit' => (float) $depreciation->amount],
                ],
            );

            $depreciation->update([
                'journal_entry_id' => $journalEntry->id,
                'posted_at' => now(),
            ]);

            return $depreciation->refresh();
        });
    }

    private function generateCode(): string
    {
        $last = FixedAsset::withoutGlobalScopes()->where('organization_id', app('orgId'))->orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->code, 5)) + 1 : 1;

        return 'TSCĐ-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
