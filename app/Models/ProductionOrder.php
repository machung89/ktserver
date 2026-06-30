<?php

namespace App\Models;

use App\Models\Scopes\OwnedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'production_number', 'product_id', 'warehouse_id', 'quantity', 'status', 'material_cost', 'labor_cost', 'overhead_cost', 'total_cost', 'unit_cost', 'production_date', 'notes', 'created_by'])]
class ProductionOrder extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'material_cost' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'overhead_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'production_date' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ProductionOrderMaterial::class);
    }

    public function costs(): HasMany
    {
        return $this->hasMany(ProductionOrderCost::class);
    }
}
