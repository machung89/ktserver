<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'type' => $this->type,
            'company_id' => $this->company_id,
            'account_id' => $this->account_id,
            'payment_date' => $this->payment_date,
            'amount' => $this->amount,
            'description' => $this->description,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'is_advance' => $this->is_advance,
            'status' => $this->status,
            'expense_account_id' => $this->expense_account_id,
            'company' => new CompanyResource($this->whenLoaded('company')),
            'account' => new AccountResource($this->whenLoaded('account')),
            'expense_account' => new AccountResource($this->whenLoaded('expenseAccount')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
