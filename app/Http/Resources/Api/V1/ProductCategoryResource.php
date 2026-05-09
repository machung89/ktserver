<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'description' => $this->description,
            'parent' => new ProductCategoryResource($this->whenLoaded('parent')),
            'children' => ProductCategoryResource::collection($this->whenLoaded('children')),
            'products_count' => $this->whenCounted('products'),
        ];
    }
}
