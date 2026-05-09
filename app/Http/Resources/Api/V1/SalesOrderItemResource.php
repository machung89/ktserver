<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'warehouse_id' => $this->warehouse_id,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'cost_price' => $this->cost_price,
            'tax_rate' => $this->tax_rate,
            'amount' => $this->amount,
            'is_return' => $this->is_return,
            'return_date' => $this->return_date,
            'return_note' => $this->return_note,
            'product' => new ProductResource($this->whenLoaded('product')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
        ];
    }
}
