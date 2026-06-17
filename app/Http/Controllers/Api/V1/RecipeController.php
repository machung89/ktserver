<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): JsonResponse
    {
        $recipes = Recipe::with([
            'product:id,name,unit,code',
            'ingredients.ingredient:id,name,unit,code',
        ])
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->search, fn ($q, $v) => $q->whereHas(
                'product', fn ($p) => $p->where('name', 'like', "%{$v}%")
            ))
            ->orderBy('id', 'desc')
            ->paginate(min((int) $request->input('per_page', 20), 200));

        return response()->json($recipes);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'type' => ['nullable', 'in:recipe,combo'],
            'yield_quantity' => ['required', 'numeric', 'min:0.0001'],
            'notes' => ['nullable', 'string'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.ingredient_id' => ['required', 'integer', 'exists:products,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:50'],
        ]);

        $recipe = Recipe::create([
            'organization_id' => $this->orgId(),
            'product_id' => $data['product_id'],
            'type' => $data['type'] ?? 'recipe',
            'yield_quantity' => $data['yield_quantity'],
            'notes' => $data['notes'] ?? null,
        ]);

        $recipe->ingredients()->createMany($data['ingredients']);

        return response()->json(['data' => $recipe->load([
            'product:id,name,unit,code',
            'ingredients.ingredient:id,name,unit,code',
        ])], 201);
    }

    public function show(Recipe $recipe): JsonResponse
    {
        return response()->json(['data' => $recipe->load([
            'product:id,name,unit,code',
            'ingredients.ingredient:id,name,unit,code',
        ])]);
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'yield_quantity' => ['sometimes', 'numeric', 'min:0.0001'],
            'notes' => ['nullable', 'string'],
            'ingredients' => ['sometimes', 'array', 'min:1'],
            'ingredients.*.ingredient_id' => ['required_with:ingredients', 'integer', 'exists:products,id'],
            'ingredients.*.quantity' => ['required_with:ingredients', 'numeric', 'min:0.0001'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:50'],
        ]);

        $recipe->update([
            'product_id' => $data['product_id'] ?? $recipe->product_id,
            'yield_quantity' => $data['yield_quantity'] ?? $recipe->yield_quantity,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $recipe->notes,
        ]);

        if (isset($data['ingredients'])) {
            $recipe->ingredients()->delete();
            $recipe->ingredients()->createMany($data['ingredients']);
        }

        return response()->json(['data' => $recipe->load([
            'product:id,name,unit,code',
            'ingredients.ingredient:id,name,unit,code',
        ])]);
    }

    public function destroy(Recipe $recipe): JsonResponse
    {
        $recipe->delete();

        return response()->json(null, 204);
    }
}
