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
            // Nhập kho: tính lại giá bình quân gia quyền (WAC).
            // Khi tồn âm (bán trước nhập sau), phần âm đã được xuất/tính giá vốn rồi
            // → chỉ tính WAC trên phần tồn không âm (max 0) để tránh avg_cost bị sai.
            $oldQty = (float) $inventory->quantity;
            $oldCost = (float) $inventory->avg_cost;
            $newQty = $oldQty + $quantity;

            if ($oldQty >= 0) {
                // Trường hợp bình thường
                $newTotal = ($oldQty * $oldCost) + ($quantity * $unitPrice);
                $newAvgCost = $newQty > 0 ? $newTotal / $newQty : $unitPrice;
            } elseif ($newQty <= 0) {
                // Vẫn còn âm sau khi nhập → giữ nguyên avg_cost
                $newAvgCost = $oldCost ?: $unitPrice;
            } else {
                // Tồn âm, nhập vào vượt qua 0 → toàn bộ tồn dương còn lại từ lần nhập này
                $newAvgCost = $unitPrice;
            }

            $inventory->update([
                'quantity' => $newQty,
                'avg_cost' => round($newAvgCost, 2),
            ]);
        } else {
            // Xuất kho: giữ nguyên avg_cost (WAC không đổi khi xuất)
            $inventory->increment('quantity', $quantity);
        }

        return (float) $inventory->fresh()->avg_cost;
    }
}
