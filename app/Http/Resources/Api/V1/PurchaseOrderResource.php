<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'company_id' => $this->company_id,
            'warehouse_id' => $this->warehouse_id,
            'order_date' => $this->order_date,
            'expected_date' => $this->expected_date,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'payment_status' => $this->payment_status,
            'paid_amount' => $this->paid_amount,
            'notes' => $this->notes,
            'company' => new CompanyResource($this->whenLoaded('company')),
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
