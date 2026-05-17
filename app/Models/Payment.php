<?php

namespace App\Models;

use App\Enums\PaymentType;
use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['payment_number', 'type', 'company_id', 'account_id', 'to_account_id', 'expense_account_id', 'payment_date', 'amount', 'description', 'organization_id', 'reference_type', 'reference_id', 'is_advance', 'status'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'is_advance' => 'boolean',
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

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
