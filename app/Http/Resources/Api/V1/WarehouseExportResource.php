<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'export_number' => $this->export_number,
            'export_date' => $this->export_date?->format('Y-m-d'),
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
            'notes' => $this->notes,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'orders_count' => $this->whenCounted('salesOrders'),
            'sales_orders' => $this->whenLoaded('salesOrders', function () {
                return $this->salesOrders->map(fn ($o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'order_date' => $o->order_date?->format('Y-m-d'),
                    'company_name' => $o->company?->name,
                    'total_amount' => (float) $o->total_amount,
                    'status' => $o->getRawOriginal('status'),
                    'items_count' => $o->relationLoaded('items') ? $o->items->count() : $o->items()->count(),
                    'items' => $o->relationLoaded('items') ? $o->items->map(fn ($item) => [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product?->name,
                        'product_code' => $item->product?->code,
                        'unit' => $item->product?->unit,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'amount' => (float) $item->amount,
                    ]) : [],
                ]);
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
