<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    use ScopedByOrganization;

    public function __construct(protected PurchaseOrderService $purchaseOrderService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = PurchaseOrder::with(['company', 'warehouse'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->company_id, fn ($q, $v) => $q->where('company_id', $v))
            ->latest()
            ->paginate($request->filled('per_page') ? (int) $request->per_page : 20);

        return PurchaseOrderResource::collection($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $order = PurchaseOrder::create([
            'order_number' => $this->generateOrderNumber(),
            'status' => OrderStatus::Draft,
            'organization_id' => $this->orgId(),
            'company_id' => $validated['company_id'],
            'warehouse_id' => $validated['warehouse_id'],
            'order_date' => $validated['order_date'],
            'expected_date' => $validated['expected_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $subtotal = 0;
        $taxAmount = 0;

        foreach ($validated['items'] as $item) {
            $amount = $item['quantity'] * $item['unit_price'];
            $taxRate = $item['tax_rate'] ?? 0;
            $subtotal += $amount;
            $taxAmount += $amount * $taxRate / 100;

            $order->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'tax_rate' => $taxRate,
                'amount' => $amount,
            ]);
        }

        $order->update(['subtotal' => $subtotal, 'tax_amount' => $taxAmount, 'total_amount' => $subtotal + $taxAmount]);

        return (new PurchaseOrderResource($order->load(['company', 'warehouse', 'items.product'])))
            ->response()->setStatusCode(201);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return new PurchaseOrderResource($purchaseOrder->load(['company', 'warehouse', 'items.product']));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        if ($purchaseOrder->status !== OrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể sửa phiếu ở trạng thái nháp.']]);
        }

        $validated = $request->validate([
            'company_id' => ['sometimes', 'exists:companies,id'],
            'warehouse_id' => ['sometimes', 'exists:warehouses,id'],
            'order_date' => ['sometimes', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $purchaseOrder->update($validated);

        return new PurchaseOrderResource($purchaseOrder->load(['company', 'warehouse', 'items.product']));
    }

    public function confirm(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        if ($purchaseOrder->status !== OrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể xác nhận phiếu ở trạng thái nháp.']]);
        }

        return new PurchaseOrderResource($this->purchaseOrderService->confirm($purchaseOrder));
    }

    public function ship(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        if ($purchaseOrder->status !== OrderStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể chuyển sang đang giao ở trạng thái đã xác nhận.']]);
        }

        $purchaseOrder->update(['status' => OrderStatus::Shipping]);

        return new PurchaseOrderResource($purchaseOrder->fresh());
    }

    public function complete(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        if (! in_array($purchaseOrder->status, [OrderStatus::Confirmed, OrderStatus::Shipping])) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể hoàn thành phiếu ở trạng thái đã xác nhận hoặc đang giao.']]);
        }

        $purchaseOrder->update(['status' => OrderStatus::Completed]);

        return new PurchaseOrderResource($purchaseOrder->fresh());
    }

    public function cancel(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        if (in_array($purchaseOrder->status, [OrderStatus::Completed])) {
            throw ValidationException::withMessages(['status' => ['Không thể hủy phiếu đã hoàn thành.']]);
        }

        return new PurchaseOrderResource($this->purchaseOrderService->cancel($purchaseOrder));
    }

    public function bulkConfirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $orders = PurchaseOrder::whereIn('id', $validated['ids'])
            ->where('status', OrderStatus::Draft)
            ->get();

        $confirmed = 0;
        $errors = [];

        foreach ($orders as $order) {
            try {
                $this->purchaseOrderService->confirm($order);
                $confirmed++;
            } catch (ValidationException $e) {
                $errors[] = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'reason' => collect($e->errors())->flatten()->first(),
                ];
            } catch (\Throwable) {
                $errors[] = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'reason' => 'Lỗi hệ thống',
                ];
            }
        }

        return response()->json([
            'confirmed' => $confirmed,
            'failed' => count($errors),
            'errors' => $errors,
        ]);
    }

    private function generateOrderNumber(): string
    {
        $last = PurchaseOrder::orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->order_number, 3)) + 1 : 1;

        return 'PO-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
