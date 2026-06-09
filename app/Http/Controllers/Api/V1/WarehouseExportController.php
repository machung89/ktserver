<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WarehouseExportResource;
use App\Models\SalesOrder;
use App\Models\WarehouseExport;
use App\Services\ActivityLogService;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseExportController extends Controller
{
    use ScopedByOrganization;

    private const STATUS_LABELS = [
        'confirmed' => 'Xác nhận',
        'shipping' => 'Giao hàng',
        'completed' => 'Hoàn thành',
    ];

    private const STATUS_LEVEL = [
        'draft' => 0,
        'confirmed' => 1,
        'shipping' => 2,
        'completed' => 3,
    ];

    public function __construct(
        private ActivityLogService $activityLog,
        private SalesOrderService $salesOrderService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $exports = WarehouseExport::with('warehouse')
            ->withCount('salesOrders')
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->where('export_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('export_date', '<=', $v))
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

        $orderCount = count($validated['order_ids'] ?? []);
        $this->activityLog->log(
            $this->orgId(), $request->user()->id,
            'warehouse_export_created',
            "Tạo phiếu xuất kho {$export->export_number}".($orderCount ? " với {$orderCount} đơn" : ''),
            null, [], 'warehouse_export', $export->id
        );

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

        $oldStatus = $warehouseExport->status;
        $oldCount = $warehouseExport->salesOrders()->count();

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

        $changes = [];
        if (array_key_exists('status', $validated) && $validated['status'] !== $oldStatus) {
            $from = self::STATUS_LABELS[$oldStatus] ?? $oldStatus;
            $to = self::STATUS_LABELS[$validated['status']] ?? $validated['status'];
            $changes[] = "trạng thái {$from} → {$to}";
        }
        if ($request->has('order_ids')) {
            $newCount = count($validated['order_ids'] ?? []);
            if ($newCount !== $oldCount) {
                $changes[] = "số đơn {$oldCount} → {$newCount}";
            }
        }

        if (! empty($changes)) {
            $this->activityLog->log(
                $this->orgId(), $request->user()->id,
                'warehouse_export_updated',
                "Cập nhật phiếu xuất {$warehouseExport->export_number}: ".implode(', ', $changes),
                null, [], 'warehouse_export', $warehouseExport->id
            );
        }

        return new WarehouseExportResource($warehouseExport->load(['warehouse', 'salesOrders.company']));
    }

    /**
     * Đổi trạng thái hàng loạt: đưa mọi đơn trong phiếu lên trạng thái đích
     * (nháp → xác nhận → [giao] → hoàn thành) trong một request, rồi cập nhật phiếu.
     */
    public function bulkStatus(Request $request, WarehouseExport $warehouseExport): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'shipping', 'completed'])],
        ]);
        $target = $validated['status'];
        $targetLevel = self::STATUS_LEVEL[$target];

        $orders = $warehouseExport->salesOrders()->get();
        $updated = 0;
        $failed = [];

        foreach ($orders as $order) {
            $current = $order->getRawOriginal('status');
            if ($current === 'cancelled' || (self::STATUS_LEVEL[$current] ?? 0) >= $targetLevel) {
                continue;
            }

            try {
                $this->advanceOrderTo($order, $target);
                $updated++;
            } catch (ValidationException $e) {
                $failed[] = $order->order_number;
            } catch (\Throwable) {
                $failed[] = $order->order_number;
            }
        }

        $warehouseExport->update(['status' => $target]);

        $this->activityLog->log(
            $this->orgId(), $request->user()->id,
            'warehouse_export_updated',
            "Đổi trạng thái phiếu xuất {$warehouseExport->export_number} → ".(self::STATUS_LABELS[$target] ?? $target)
                ." ({$updated} đơn".(count($failed) ? ', '.count($failed).' đơn lỗi' : '').')',
            null, [], 'warehouse_export', $warehouseExport->id
        );

        return response()->json([
            'data' => new WarehouseExportResource($warehouseExport->load(['warehouse', 'salesOrders.company', 'salesOrders.items.product'])),
            'updated' => $updated,
            'failed' => $failed,
        ]);
    }

    /**
     * Đưa 1 đơn lần lượt qua các bước cho tới trạng thái đích.
     */
    private function advanceOrderTo(SalesOrder $order, string $target): void
    {
        if ($order->status === OrderStatus::Draft) {
            $this->salesOrderService->confirm($order);
            $order->refresh();
        }

        if ($target === 'confirmed') {
            return;
        }

        if ($target === 'shipping') {
            if ($order->status === OrderStatus::Confirmed) {
                $this->salesOrderService->ship($order);
            }

            return;
        }

        if ($target === 'completed') {
            $this->salesOrderService->complete($order);
        }
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
