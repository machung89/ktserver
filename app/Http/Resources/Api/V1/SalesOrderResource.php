<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'company_id' => $this->company_id,
            'restaurant_table_id' => $this->restaurant_table_id,
            'restaurant_table_name' => $this->whenLoaded('restaurantTable', fn () => $this->restaurantTable?->name),
            'order_date' => $this->order_date,
            'expected_date' => $this->expected_date,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'standard_total' => $this->standard_total,
            'employee_profit' => $this->employee_profit,
            'payment_status' => $this->payment_status,
            'paid_amount' => $this->paid_amount,
            'has_export' => (bool) ($this->warehouse_exports_exists ?? false),
            'original_order_id' => $this->original_order_id,
            'original_order_number' => $this->whenLoaded('originalOrder', fn () => $this->originalOrder?->order_number),
            'return_order_id' => $this->whenLoaded('returnOrder', fn () => $this->returnOrder?->id),
            'return_order_number' => $this->whenLoaded('returnOrder', fn () => $this->returnOrder?->order_number),
            'has_return' => $this->when(
                $this->relationLoaded('returnOrder'),
                fn () => $this->returnOrder !== null,
                fn () => (bool) ($this->return_order_exists ?? false),
            ),
            'promotion_id' => $this->promotion_id,
            'promotion_name' => $this->whenLoaded('promotion', fn () => $this->promotion?->name),
            'ref_id' => $this->ref_id,
            'tracking_number' => $this->tracking_number,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'company' => new CompanyResource($this->whenLoaded('company')),
            'items' => SalesOrderItemResource::collection($this->whenLoaded('items')),
            // Lịch sử thanh toán = phiếu gắn trực tiếp + phần được phân bổ từ phiếu thu gộp/tiền thu trước
            'payments' => $this->when(
                $this->relationLoaded('payments') || $this->relationLoaded('allocations'),
                fn () => collect()
                    ->merge($this->relationLoaded('payments') ? $this->payments->map(fn ($p) => [
                        'id' => 'pay-'.$p->id,
                        'payment_id' => $p->id,
                        'payment_number' => $p->payment_number,
                        'payment_date' => $p->payment_date,
                        'amount' => (float) $p->amount,
                        'description' => $p->description,
                        'account_name' => $p->account?->name,
                        'status' => $p->status,
                        'allocated' => false,
                    ]) : [])
                    ->merge($this->relationLoaded('allocations') ? $this->allocations->map(fn ($a) => [
                        'id' => 'alloc-'.$a->id,
                        'allocation_id' => $a->id,
                        'payment_id' => $a->payment_id,
                        'payment_number' => $a->payment?->payment_number,
                        'payment_date' => $a->payment?->payment_date,
                        'amount' => (float) $a->amount,
                        'description' => $a->payment?->description,
                        'account_name' => $a->payment?->account?->name,
                        'status' => $a->payment?->status,
                        'allocated' => true,
                    ]) : [])
                    ->sortBy('payment_date')
                    ->values()
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
