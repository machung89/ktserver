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
            'min_quantity' => $this->min_quantity,
            'is_low_stock' => $this->quantity <= $this->min_quantity,
            'product_code' => $this->product?->code,
            'product_name' => $this->product?->name,
            'unit' => $this->product?->unit,
            'warehouse' => $this->warehouse?->name,
            'product' => new ProductResource($this->whenLoaded('product')),
            'updated_at' => $this->updated_at,
        ];
    }
}
