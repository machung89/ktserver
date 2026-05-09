<?php

namespace App\Models;

use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'warehouse_id', 'quantity', 'avg_cost', 'reserved_quantity', 'min_quantity', 'organization_id'])]
class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'avg_cost' => 'decimal:2',
            'reserved_quantity' => 'decimal:3',
            'min_quantity' => 'decimal:3',
        ];
    }

    public function getAvailableQuantityAttribute(): float
    {
        return max(0, (float) $this->quantity - (float) $this->reserved_quantity);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
