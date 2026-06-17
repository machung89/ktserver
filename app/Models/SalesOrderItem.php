<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sales_order_id', 'product_id', 'warehouse_id', 'quantity', 'is_served', 'unit_price', 'discount_type', 'discount_value', 'cost_price', 'standard_price', 'tax_rate', 'amount', 'order_discount_alloc', 'is_return', 'return_date', 'return_note'])]
class SalesOrderItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'is_served' => 'boolean',
            'unit_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'standard_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'is_return' => 'boolean',
            'return_date' => 'date',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
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
