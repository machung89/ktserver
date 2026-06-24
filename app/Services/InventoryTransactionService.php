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
        Inventory::firstOrCreate($key, ['quantity' => 0, 'avg_cost' => 0, 'stock_value' => 0, 'min_quantity' => 0]);

        // Lock bản ghi để tránh race condition khi nhiều request cùng nhập/xuất kho
        $inventory = Inventory::where($key)->lockForUpdate()->first();

        $oldQty = (float) $inventory->quantity;
        $oldCost = (float) $inventory->avg_cost;
        $newQty = $oldQty + $quantity;

        // Giá trị tồn theo sổ (perpetual): cộng dồn giá trị thực đã luân chuyển.
        // Nhập cộng theo giá mua thật; xuất trừ theo giá vốn đã ghi 632 (= unit_price truyền vào).
        // Nhờ vậy stock_value luôn = số dư TK 156 của SP, kể cả khi tồn âm.
        $newValue = round((float) $inventory->stock_value + $quantity * $unitPrice, 2);

        if ($quantity > 0) {
            // Nhập kho: tính lại giá bình quân gia quyền (WAC).
            // Khi tồn âm (bán trước nhập sau), phần âm đã được xuất/tính giá vốn rồi
            // → chỉ tính WAC trên phần tồn không âm (max 0) để tránh avg_cost bị sai.
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
        } else {
            // Xuất kho: WAC = giá trị tồn còn lại / số lượng còn lại.
            // - Xuất bán tại giá vốn BQ → giá trị giảm đúng theo avg → avg KHÔNG đổi.
            // - Đảo phiếu nhập/điều chỉnh theo giá lô gốc (≠ avg) → avg tự tính lại cho khớp giá trị tồn.
            // Chỉ tính lại khi còn tồn dương và giá trị không âm; ngược lại (tồn âm/giá trị âm) giữ nguyên.
            $newAvgCost = ($newQty > 0 && $newValue >= 0) ? $newValue / $newQty : $oldCost;
        }

        $inventory->update([
            'quantity' => $newQty,
            'avg_cost' => round($newAvgCost, 2),
            'stock_value' => $newValue,
        ]);

        return (float) $inventory->fresh()->avg_cost;
    }

    /**
     * Chênh lệch giá vốn tạm tính so với giá thực của một bản ghi tồn kho.
     * = stock_value (giá vốn thực đã luân chuyển) − giá trị tồn theo WAC (quantity × avg_cost).
     * Khác 0 khi đã bán-trước-nhập-sau với giá tạm khác giá nhập thật.
     */
    public function cogsVariance(Inventory $inventory): float
    {
        return round(
            (float) $inventory->stock_value - round((float) $inventory->quantity * (float) $inventory->avg_cost, 2),
            2
        );
    }
}
