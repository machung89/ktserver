<?php

namespace App\Models;

use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\FixedAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'name', 'description', 'organization_id', 'company_id', 'account_id',
    'expense_account_id', 'purchase_date', 'depreciation_start_date', 'cost',
    'residual_value', 'useful_life_years', 'asset_account_code',
    'accumulated_depreciation_account_code', 'status',
])]
class FixedAsset extends Model
{
    /** @use HasFactory<FixedAssetFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'depreciation_start_date' => 'date',
            'cost' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'useful_life_years' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    public function annualDepreciation(): float
    {
        $depreciable = round((float) $this->cost - (float) $this->residual_value);

        return round($depreciable / (int) $this->useful_life_years);
    }

    /**
     * Khấu hao của một năm cụ thể (VND: đồng nguyên). Năm cuối ôm phần dư làm tròn
     * để Σ khấu hao = nguyên giá − giá trị thanh lý tuyệt đối.
     */
    public function depreciationForYear(int $year): float
    {
        $startYear = (int) $this->depreciation_start_date->format('Y');
        $idx = $year - $startYear;
        $life = (int) $this->useful_life_years;
        $totalDepreciable = round((float) $this->cost - (float) $this->residual_value);
        $annual = round($totalDepreciable / $life);

        return ($idx === $life - 1)
            ? $totalDepreciable - $annual * ($life - 1)   // năm cuối = phần còn lại
            : $annual;
    }

    /** @return array<int, array{year: int, amount: float, posted: bool, journal_entry_id: int|null, posted_at: string|null}> */
    public function depreciationSchedule(): array
    {
        $startYear = (int) $this->depreciation_start_date->format('Y');
        $posted = $this->depreciations->keyBy('year');
        $accumulated = 0.0;

        $schedule = [];
        for ($i = 0; $i < $this->useful_life_years; $i++) {
            $year = $startYear + $i;
            $amount = $this->depreciationForYear($year);
            $accumulated += $amount;

            $record = $posted->get($year);
            $schedule[] = [
                'year' => $year,
                'amount' => $amount,
                'accumulated' => round($accumulated, 2),
                'book_value' => round((float) $this->cost - $accumulated, 2),
                'posted' => $record !== null,
                'journal_entry_id' => $record?->journal_entry_id,
                'posted_at' => $record?->posted_at?->toDateTimeString(),
            ];
        }

        return $schedule;
    }
}
