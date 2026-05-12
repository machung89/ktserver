<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'scope' => $this->scope,
            'conditions' => $this->conditions,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_active' => $this->is_active,
            'products' => ProductResource::collection($this->whenLoaded('products')),
            'product_ids' => $this->whenLoaded('products', fn () => $this->products->pluck('id')->values()),
            'categories' => ProductCategoryResource::collection($this->whenLoaded('categories')),
            'category_ids' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')->values()),
            'created_at' => $this->created_at,
        ];
    }
}
