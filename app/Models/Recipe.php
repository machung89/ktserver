<?php

namespace App\Models;

use App\Models\Scopes\OwnedByOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = [
        'organization_id',
        'product_id',
        'yield_quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'yield_quantity' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }
}
