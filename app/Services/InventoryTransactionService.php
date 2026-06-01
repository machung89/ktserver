<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use Illuminate\Database\Eloquent\Model;

class InventoryTransactionService
{
    /**
     * @param  array<array{product_id: int, quantity: float, unit_price: float}>  $items
     */
    public function post(TransactionType $type, int $warehouseId, string $date, Model $reference, array $items, ?string $description = null): InventoryTransaction
    {
        $transaction = InventoryTransaction::create([
            'type' => $type,
            'warehouse_id' => $warehouseId,
            'transaction_date' => $date,
            'description' => $description,
            'is_posted' => true,
            'organization_id' => app('orgId'),
        ]);

        $transaction->reference()->associate($reference);
        $transaction->save();

        foreach ($items as $item) {
            $transaction->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
            ]);

            $this->updateInventoryBalance($warehouseId, $item['product_id'], $item['quantity'], $item['unit_price']);
        }

        return $transaction->load('items.product');
    }

    /**
     * Cập nhật tồn kho và tính lại giá bình quân gia quyền (WAC) khi nhập.
     * Khi xuất (quantity < 0), giá bình quân không thay đổi.
     *
     * Phải gọi trong DB::transaction() để lockForUpdate() có hiệu lực.
     *
     * @return float avg_cost hiện tại sau khi cập nhật
     */
    public function updateInventoryBalance(int $warehouseId, int $productId, float $quantity, float $unitPrice = 0): float
    {
        $orgId = app('orgId');
        $key = ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'organization_id' => $orgId];

        // Đảm bảo bản ghi tồn tại trước khi lock
        Inventory::firstOrCreate($key, ['quantity' => 0, 'avg_cost' => 0, 'min_quantity' => 0]);

        // Lock bản ghi để tránh race condition khi nhiều request cùng nhập/xuất kho
        $inventory = Inventory::where($key)->lockForUpdate()->first();

        if ($quantity > 0) {
            // Nhập kho: tính lại giá bình quân gia quyền (WAC)
            $oldQty = (float) $inventory->quantity;
            $oldCost = (float) $inventory->avg_cost;
            $newTotal = ($oldQty * $oldCost) + ($quantity * $unitPrice);
            $newQty = $oldQty + $quantity;
            $newAvgCost = $newQty > 0 ? $newTotal / $newQty : $unitPrice;

            $inventory->update([
                'quantity' => $newQty,
                'avg_cost' => round($newAvgCost, 2),
            ]);
        } else {
            // Xuất kho: SQL UPDATE atomic, giữ nguyên avg_cost
            $inventory->increment('quantity', $quantity);
        }

        return (float) $inventory->fresh()->avg_cost;
    }
}
