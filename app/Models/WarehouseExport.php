<?php

namespace App\Models;

use App\Models\Scopes\OwnedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['organization_id', 'export_number', 'export_date', 'warehouse_id', 'notes', 'status', 'created_by'])]
class WarehouseExport extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'export_date' => 'date',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function salesOrders(): BelongsToMany
    {
        return $this->belongsToMany(SalesOrder::class, 'warehouse_export_orders')
            ->withTimestamps();
    }
}
