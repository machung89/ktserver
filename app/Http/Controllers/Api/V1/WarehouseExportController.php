<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WarehouseExportResource;
use App\Models\SalesOrder;
use App\Models\WarehouseExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class WarehouseExportController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        $exports = WarehouseExport::with('warehouse')
            ->withCount('salesOrders')
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('export_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('export_date', '<=', $v))
            ->when($request->search, fn ($q, $v) => $q->where('export_number', 'like', "%{$v}%"))
            ->orderByDesc('export_date')
            ->orderByDesc('id')
            ->paginate(20);

        return WarehouseExportResource::collection($exports);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'export_date' => ['required', 'date'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['confirmed', 'shipping', 'completed'])],
            'order_ids' => ['sometimes', 'array'],
            'order_ids.*' => ['integer', 'exists:sales_orders,id'],
        ]);

        $export = WarehouseExport::create([
            'organization_id' => $this->orgId(),
            'export_number' => $this->generateExportNumber(),
            'export_date' => $validated['export_date'],
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'] ?? 'confirmed',
            'created_by' => $request->user()->id,
        ]);

        if (! empty($validated['order_ids'])) {
            $export->salesOrders()->sync($validated['order_ids']);
        }

        return (new WarehouseExportResource($export->load(['warehouse', 'salesOrders.company'])))
            ->response()->setStatusCode(201);
    }

    public function show(WarehouseExport $warehouseExport): WarehouseExportResource
    {
        return new WarehouseExportResource(
            $warehouseExport->load(['warehouse', 'salesOrders.company', 'salesOrders.items.product'])
        );
    }

    public function update(Request $request, WarehouseExport $warehouseExport): WarehouseExportResource
    {
        $validated = $request->validate([
            'export_date' => ['sometimes', 'date'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['confirmed', 'shipping', 'completed'])],
            'order_ids' => ['sometimes', 'array'],
            'order_ids.*' => ['integer', 'exists:sales_orders,id'],
        ]);

        $warehouseExport->update(collect($validated)->except('order_ids')->toArray());

        if ($request->has('order_ids')) {
            $newIds = $validated['order_ids'] ?? [];
            $removedIds = $warehouseExport->salesOrders()->pluck('sales_orders.id')->diff($newIds)->values()->all();

            $warehouseExport->salesOrders()->sync($newIds);

            if (! empty($removedIds)) {
                SalesOrder::whereIn('id', $removedIds)
                    ->whereIn('status', ['shipping', 'completed'])
                    ->update(['status' => 'confirmed']);
            }
        }

        return new WarehouseExportResource($warehouseExport->load(['warehouse', 'salesOrders.company']));
    }

    public function destroy(WarehouseExport $warehouseExport): JsonResponse
    {
        $warehouseExport->delete();

        return response()->json(null, 204);
    }

    private function generateExportNumber(): string
    {
        $last = WarehouseExport::where('organization_id', $this->orgId())
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $seq = $last ? ((int) ltrim(substr($last->export_number, 3), '0') ?: 0) + 1 : 1;

        return 'XK-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
