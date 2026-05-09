<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WarehouseResource;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    use ScopedByOrganization;

    public function index(): AnonymousResourceCollection
    {
        return WarehouseResource::collection(Warehouse::all());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', Rule::unique('warehouses', 'code')->where('organization_id', $this->orgId())],
            'name' => ['required', 'string'],
            'address' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $warehouse = Warehouse::create(array_merge($validated, ['organization_id' => $this->orgId()]));

        return (new WarehouseResource($warehouse))->response()->setStatusCode(201);
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        return new WarehouseResource($warehouse);
    }

    public function update(Request $request, Warehouse $warehouse): WarehouseResource
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', Rule::unique('warehouses', 'code')->ignore($warehouse->id)->where('organization_id', $this->orgId())],
            'name' => ['sometimes', 'string'],
            'address' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $warehouse->update($validated);

        return new WarehouseResource($warehouse);
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        $warehouse->delete();

        return response()->json(null, 204);
    }
}
