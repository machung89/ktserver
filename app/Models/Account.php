<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'type', 'parent_code', 'is_active', 'organization_id'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_active' => 'boolean',
        ];
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
