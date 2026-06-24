<?php

namespace App\Models;

use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['product_id', 'warehouse_id', 'quantity', 'avg_cost', 'stock_value', 'reserved_quantity', 'min_quantity', 'organization_id'])]
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
            'stock_value' => 'decimal:2',
            'reserved_quantity' => 'decimal:3',
            'min_quantity' => 'decimal:3',
        ];
    }

    public function getAvailableQuantityAttribute(): float
    {
        return max(0, (float) $this->quantity - (float) $this->reserved_quantity);
    }

    /**
     * Đảm bảo bản ghi tồn kho tồn tại mà không gây deadlock khi nhiều request đồng thời.
     * Dùng INSERT IGNORE thay vì firstOrCreate để tránh gap lock trên InnoDB.
     */
    public static function ensureExists(int $productId, int $warehouseId, int $orgId): void
    {
        DB::table('inventories')->insertOrIgnore([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'organization_id' => $orgId,
            'quantity' => 0,
            'avg_cost' => 0,
            'stock_value' => 0,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
