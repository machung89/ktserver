<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date,
            'description' => $this->description,
            'is_posted' => $this->is_posted,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'total_debit' => $this->total_debit,
            'total_credit' => $this->total_credit,
            'lines' => JournalEntryLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
