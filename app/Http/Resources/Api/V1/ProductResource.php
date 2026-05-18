<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_path ? asset('storage/'.$this->image_path) : null,
            'unit' => $this->unit,
            'category_id' => $this->category_id,
            'category' => new ProductCategoryResource($this->whenLoaded('category')),
            'price' => $this->price,
            'standard_price' => $this->standard_price,
            'cost_price' => $this->cost_price,
            'is_active' => $this->is_active,
            'units' => $this->whenLoaded('units', fn () => $this->units->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'conversion_factor' => (float) $u->conversion_factor,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
