<?php

namespace App\Models;

use App\Models\Scopes\OwnedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'tour_number', 'name', 'customer_id', 'start_date', 'end_date', 'num_guests', 'num_adults', 'num_children', 'unit_price', 'child_price', 'vat_rate', 'subtotal', 'tax_amount', 'total_amount', 'paid_amount', 'extra_revenues_total', 'status', 'stage', 'is_featured', 'notes', 'created_by'])]
class Tour extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
            'unit_price' => 'decimal:2',
            'child_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'extra_revenues_total' => 'decimal:2',
            'num_guests' => 'integer',
            'num_adults' => 'integer',
            'num_children' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'customer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function services(): HasMany
    {
        return $this->hasMany(TourService::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(TourPaymentRequest::class);
    }

    public function extraRevenues(): HasMany
    {
        return $this->hasMany(TourExtraRevenue::class);
    }
}
