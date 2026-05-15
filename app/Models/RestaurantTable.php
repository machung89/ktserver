<?php

namespace App\Models;

use App\Models\Scopes\OwnedByOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RestaurantTable extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'capacity',
        'status',
        'notes',
        'sort_order',
        'reserved_name',
        'reserved_phone',
        'reserved_company_id',
        'qr_token',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);

        static::creating(function (RestaurantTable $table) {
            if (! $table->qr_token) {
                $table->qr_token = (string) Str::uuid();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reservedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'reserved_company_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function activeOrder(): HasMany
    {
        return $this->hasMany(SalesOrder::class)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->latest();
    }
}
