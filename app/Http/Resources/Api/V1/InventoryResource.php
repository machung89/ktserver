<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'warehouse_id' => $this->warehouse_id,
            'quantity' => $this->quantity,
            'reserved_quantity' => $this->reserved_quantity,
            'available_quantity' => $this->available_quantity,
            'avg_cost' => $this->avg_cost,
            'min_quantity' => $this->min_quantity,
            'sales_velocity' => $this->sales_velocity ?? null,
            'days_of_cover' => $this->days_of_cover ?? null,
            'is_low_stock' => $this->isLowStock(),
            'product_code' => $this->product?->code,
            'product_name' => $this->product?->name,
            'unit' => $this->product?->unit,
            'warehouse' => $this->warehouse?->name,
            'product' => new ProductResource($this->whenLoaded('product')),
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Sắp hết hàng khi: hàng có thể bán ≤ mức tối thiểu,
     * HOẶC số ngày tồn còn lại < ngưỡng (theo tốc độ bán).
     */
    private function isLowStock(): bool
    {
        $available = (float) $this->available_quantity;

        if ($available <= (float) $this->min_quantity) {
            return true;
        }

        $daysOfCover = $this->days_of_cover ?? null;
        $coverDays = $this->low_stock_cover_days ?? null;

        return $daysOfCover !== null && $coverDays !== null && $daysOfCover < $coverDays;
    }
}
