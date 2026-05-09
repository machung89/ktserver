<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'description' => $this->description,
            'debit_amount' => $this->debit_amount,
            'credit_amount' => $this->credit_amount,
            'account' => new AccountResource($this->whenLoaded('account')),
        ];
    }
}
