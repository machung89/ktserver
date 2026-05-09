<?php

namespace App\Models;

use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['entry_number', 'entry_date', 'description', 'is_posted', 'organization_id'])]
class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'is_posted' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTotalDebitAttribute(): string
    {
        return $this->lines->sum('debit_amount');
    }

    public function getTotalCreditAttribute(): string
    {
        return $this->lines->sum('credit_amount');
    }
}
