<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasPaymentStatus;
use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\SalesOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['order_number', 'ref_id', 'original_order_id', 'tracking_number', 'company_id', 'restaurant_table_id', 'order_date', 'expected_date', 'status', 'subtotal', 'tax_amount', 'total_amount', 'standard_total', 'employee_profit', 'notes', 'promotion_id', 'organization_id', 'payment_status', 'paid_amount', 'created_by'])]
class SalesOrder extends Model
{
    /** @use HasFactory<SalesOrderFactory> */
    use HasFactory, HasPaymentStatus;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'standard_total' => 'decimal:2',
            'employee_profit' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'reference');
    }

    public function inventoryTransactions(): MorphMany
    {
        return $this->morphMany(InventoryTransaction::class, 'reference');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'reference');
    }

    public function warehouseExports(): BelongsToMany
    {
        return $this->belongsToMany(WarehouseExport::class, 'warehouse_export_orders');
    }

    public function originalOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'original_order_id');
    }

    public function returnOrder(): HasOne
    {
        return $this->hasOne(SalesOrder::class, 'original_order_id');
    }
}
