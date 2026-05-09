<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\InventoryTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['type', 'warehouse_id', 'transaction_date', 'description', 'is_posted', 'organization_id'])]
class InventoryTransaction extends Model
{
    /** @use HasFactory<InventoryTransactionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'transaction_date' => 'date',
            'is_posted' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransactionItem::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
