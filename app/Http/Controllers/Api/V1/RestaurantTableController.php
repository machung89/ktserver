<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\RestaurantTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantTableController extends Controller
{
    use ScopedByOrganization;

    public function index(): JsonResponse
    {
        $tables = RestaurantTable::with([
            'activeOrder' => fn ($q) => $q->select('id', 'restaurant_table_id', 'order_number', 'status', 'total_amount', 'order_date'),
            'reservedCompany' => fn ($q) => $q->select('id', 'name', 'phone'),
        ])->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $tables]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'capacity' => 'nullable|integer|min:1|max:100',
            'notes' => 'nullable|string|max:200',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $table = RestaurantTable::create([
            ...$data,
            'organization_id' => $this->orgId(),
            'status' => 'available',
        ]);

        return response()->json(['data' => $table], 201);
    }

    public function update(Request $request, RestaurantTable $restaurantTable): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:50',
            'capacity' => 'nullable|integer|min:1|max:100',
            'status' => 'sometimes|in:available,occupied,reserved',
            'notes' => 'nullable|string|max:200',
            'sort_order' => 'nullable|integer|min:0',
            'reserved_name' => 'nullable|string|max:100',
            'reserved_phone' => 'nullable|string|max:20',
        ]);

        // Auto find-or-create customer by phone when saving reservation
        if (! empty($data['reserved_phone'])) {
            $company = Company::firstOrCreate(
                ['organization_id' => $this->orgId(), 'phone' => $data['reserved_phone']],
                ['name' => $data['reserved_name'] ?: $data['reserved_phone'], 'type' => 'customer']
            );
            $data['reserved_company_id'] = $company->id;
        } elseif (array_key_exists('reserved_phone', $data) && empty($data['reserved_phone'])) {
            $data['reserved_company_id'] = null;
        }

        $restaurantTable->update($data);

        return response()->json(['data' => $restaurantTable->fresh(['reservedCompany'])]);
    }

    public function destroy(RestaurantTable $restaurantTable): JsonResponse
    {
        $restaurantTable->delete();

        return response()->json(null, 204);
    }
}
