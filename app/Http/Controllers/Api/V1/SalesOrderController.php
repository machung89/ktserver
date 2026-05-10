<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SalesOrderResource;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderController extends Controller
{
    use ScopedByOrganization;

    public function __construct(protected SalesOrderService $salesOrderService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = SalesOrder::with(['company', 'createdBy'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->company_id, fn ($q, $v) => $q->where('company_id', $v))
            ->when($request->search, function ($q, $v) {
                $q->where(function ($q) use ($v) {
                    $q->where('order_number', 'like', "%{$v}%")
                        ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$v}%"));
                });
            })
            ->latest()
            ->paginate($request->filled('per_page') ? (int) $request->per_page : 20);

        return SalesOrderResource::collection($orders);
    }

    public function counts(): JsonResponse
    {
        $counts = SalesOrder::selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->getRawOriginal('status') => (int) $r->cnt]);

        return response()->json([
            'all' => (int) SalesOrder::count(),
            'draft' => $counts['draft'] ?? 0,
            'confirmed' => $counts['confirmed'] ?? 0,
            'shipping' => $counts['shipping'] ?? 0,
            'completed' => $counts['completed'] ?? 0,
            'cancelled' => $counts['cancelled'] ?? 0,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'order_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.warehouse_id' => ['required', 'exists:warehouses,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'in:percent,fixed'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $orgId = $this->orgId();
        $org = Organization::find($orgId);

        // Kiểm tra tồn kho khả dụng trước khi tạo đơn
        if (! $org->setting('allow_negative_stock', false)) {
            $stockErrors = [];
            foreach ($validated['items'] as $item) {
                $inventory = Inventory::where([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $item['warehouse_id'],
                    'organization_id' => $orgId,
                ])->first();

                $available = $inventory ? (float) $inventory->available_quantity : 0;
                $requested = (float) $item['quantity'];

                if ($requested > $available) {
                    $product = Product::find($item['product_id']);
                    $warehouse = Warehouse::find($item['warehouse_id']);
                    $stockErrors[] = [
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'product_name' => $product?->name ?? '',
                        'product_code' => $product?->code ?? '',
                        'warehouse_name' => $warehouse?->name ?? '',
                        'requested' => $requested,
                        'available' => $available,
                        'shortage' => $requested - $available,
                    ];
                }
            }

            if (! empty($stockErrors)) {
                return response()->json([
                    'message' => 'Tồn kho không đủ cho một số sản phẩm.',
                    'stock_errors' => $stockErrors,
                ], 422);
            }
        }

        $order = DB::transaction(function () use ($validated, $orgId) {
            $order = SalesOrder::create([
                'order_number' => $this->generateOrderNumber(),
                'status' => OrderStatus::Draft,
                'organization_id' => $orgId,
                'company_id' => $validated['company_id'],
                'order_date' => $validated['order_date'],
                'notes' => $validated['notes'] ?? null,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'created_by' => Auth::id(),
            ]);

            $subtotal = 0;
            $taxAmount = 0;

            foreach ($validated['items'] as $item) {
                $base = (float) $item['quantity'] * (float) $item['unit_price'];
                $discountType = $item['discount_type'] ?? null;
                $discountValue = (float) ($item['discount_value'] ?? 0);
                $discountAmount = match ($discountType) {
                    'percent' => $base * $discountValue / 100,
                    'fixed' => min($discountValue, $base),
                    default => 0,
                };
                $amount = $base - $discountAmount;
                $taxRate = (float) ($item['tax_rate'] ?? 0);
                $subtotal += $amount;
                $taxAmount += $amount * $taxRate / 100;

                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $item['warehouse_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'cost_price' => $item['cost_price'] ?? 0,
                    'tax_rate' => $taxRate,
                    'amount' => $amount,
                ]);

                // Giữ chỗ tồn kho
                $inventory = Inventory::firstOrCreate(
                    ['product_id' => $item['product_id'], 'warehouse_id' => $item['warehouse_id'], 'organization_id' => $orgId],
                    ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                );
                $inventory->increment('reserved_quantity', (float) $item['quantity']);
            }

            $order->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $subtotal + $taxAmount,
            ]);

            return $order;
        });

        return (new SalesOrderResource($order->load(['company', 'createdBy', 'items.product', 'items.warehouse'])))
            ->response()->setStatusCode(201);
    }

    public function show(SalesOrder $salesOrder): SalesOrderResource
    {
        return new SalesOrderResource($salesOrder->load(['company', 'createdBy', 'items.product', 'items.warehouse']));
    }

    public function update(Request $request, SalesOrder $salesOrder): SalesOrderResource
    {
        if ($salesOrder->status !== OrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể sửa đơn ở trạng thái nháp.']]);
        }

        $validated = $request->validate([
            'company_id' => ['sometimes', 'exists:companies,id'],
            'order_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $salesOrder->update($validated);

        return new SalesOrderResource($salesOrder->load(['company', 'items.product']));
    }

    public function confirm(SalesOrder $salesOrder): SalesOrderResource
    {
        if ($salesOrder->status !== OrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể xác nhận đơn ở trạng thái nháp.']]);
        }

        return new SalesOrderResource($this->salesOrderService->confirm($salesOrder));
    }

    public function ship(SalesOrder $salesOrder): SalesOrderResource
    {
        if ($salesOrder->status !== OrderStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể giao đơn ở trạng thái đã xác nhận.']]);
        }

        $salesOrder->update(['status' => OrderStatus::Shipping]);

        return new SalesOrderResource($salesOrder->fresh());
    }

    public function complete(SalesOrder $salesOrder): SalesOrderResource
    {
        if (! in_array($salesOrder->status, [OrderStatus::Confirmed, OrderStatus::Shipping])) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể hoàn thành đơn ở trạng thái đã xác nhận hoặc đang giao.']]);
        }

        $salesOrder->update(['status' => OrderStatus::Completed]);

        return new SalesOrderResource($salesOrder->fresh());
    }

    public function returnItems(Request $request, SalesOrder $salesOrder): SalesOrderResource
    {
        $validated = $request->validate([
            'return_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.warehouse_id' => ['required', 'exists:warehouses,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        return new SalesOrderResource($this->salesOrderService->createReturnItems($salesOrder, $validated));
    }

    public function cancel(SalesOrder $salesOrder): SalesOrderResource
    {
        if (in_array($salesOrder->status, [OrderStatus::Completed])) {
            throw ValidationException::withMessages(['status' => ['Không thể hủy đơn đã hoàn thành.']]);
        }

        return new SalesOrderResource($this->salesOrderService->cancel($salesOrder));
    }

    public function bulkConfirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $orders = SalesOrder::whereIn('id', $validated['ids'])
            ->where('status', OrderStatus::Draft)
            ->get();

        $confirmed = 0;
        $errors = [];

        foreach ($orders as $order) {
            try {
                $this->salesOrderService->confirm($order);
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
        $last = SalesOrder::orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->order_number, 3)) + 1 : 1;

        return 'DH-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
