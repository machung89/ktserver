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
            // URL tuyệt đối trỏ thẳng backend (theo APP_URL) — production frontend khác origin
            // không có /storage nên link tương đối bị vỡ; ảnh <img> cross-origin không cần CORS nên load bình thường.
            'image_url' => $this->image_path ? rtrim(config('app.url'), '/').'/storage/'.$this->image_path : null,
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
            'is_combo' => $this->whenLoaded('recipe', fn () => $this->recipe?->type === 'combo'),
            'combo_components' => $this->whenLoaded('recipe', fn () => ($this->recipe && $this->recipe->type === 'combo' && $this->recipe->relationLoaded('ingredients'))
                ? $this->recipe->ingredients->map(fn ($i) => [
                    'product_id' => $i->ingredient_id,
                    'name' => $i->ingredient?->name,
                    'quantity' => (float) $i->quantity,
                    'unit' => $i->unit ?? $i->ingredient?->unit,
                ])->values()
                : []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
