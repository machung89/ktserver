<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tour_id', 'service_stage', 'service_type', 'name', 'supplier_id', 'unit_price', 'quantity', 'days', 'tax_rate', 'cost', 'paid_amount', 'advance_amount', 'guide_paid_amount', 'notes'])]
class TourService extends Model
{
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'cost' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'guide_paid_amount' => 'decimal:2',
            'quantity' => 'integer',
            'days' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_id');
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(TourPaymentRequest::class);
    }
}
