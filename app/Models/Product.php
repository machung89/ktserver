<?php

namespace App\Models;

use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['code', 'barcode', 'name', 'description', 'image_path', 'unit', 'category_id', 'price', 'standard_price', 'cost_price', 'is_active', 'product_type', 'organization_id'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'standard_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('conversion_factor');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    /**
     * Định mức/Combo của sản phẩm (1 sản phẩm tối đa 1 recipe — unique product_id).
     */
    public function recipe(): HasOne
    {
        return $this->hasOne(Recipe::class);
    }
}
