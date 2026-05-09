<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['journal_entry_id', 'account_id', 'description', 'debit_amount', 'credit_amount'])]
class JournalEntryLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
