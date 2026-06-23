<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'tax_code' => $this->tax_code,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'ward' => $this->ward,
            'representative' => $this->representative,
            'is_active' => $this->is_active,
            'user_id' => $this->user_id,
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ]),
            'bank_id' => $this->bank_id,
            'bank_account_name' => $this->bank_account_name,
            'bank_account_number' => $this->bank_account_number,
            'bank' => $this->whenLoaded('bank', fn () => [
                'id' => $this->bank->id,
                'short_name' => $this->bank->short_name,
                'name' => $this->bank->name,
                'logo' => $this->bank->logo,
                'bin' => $this->bank->bin,
            ]),
            'receivable_balance' => $this->when(
                array_key_exists('receivable_balance', $this->resource->getAttributes()),
                fn () => max(0, (float) ($this->receivable_balance ?? 0))
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
