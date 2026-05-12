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
use App\Models\User;
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
        /** @var User $user */
        $user = Auth::user();
        $canViewAll = $user->hasPermission('sales.view_all');

        $orders = SalesOrder::with(['company', 'createdBy'])
            ->when(! $canViewAll, fn ($q) => $q->where('created_by', Auth::id()))
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
        /** @var User $user */
        $user = Auth::user();
        $canViewAll = $user->hasPermission('sales.view_all');
        $base = SalesOrder::when(! $canViewAll, fn ($q) => $q->where('created_by', Auth::id()));

        $counts = (clone $base)->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->getRawOriginal('status') => (int) $r->cnt]);

        return response()->json([
            'all' => (int) (clone $base)->count(),
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
            'discount_type' => ['nullable', 'in:percent,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'promotion_id' => ['nullable', 'integer'],
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
        $allowNegativeStock = $org->setting('allow_negative_stock', false);
        $stockErrors = [];

        try {
            $order = DB::transaction(function () use ($validated, $orgId, $allowNegativeStock, &$stockErrors) {
                if (! $allowNegativeStock) {
                    foreach ($validated['items'] as $item) {
                        $inventory = Inventory::where([
                            'product_id' => $item['product_id'],
                            'warehouse_id' => $item['warehouse_id'],
                            'organization_id' => $orgId,
                        ])->lockForUpdate()->first();

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
                        throw new \RuntimeException('__stock_insufficient__');
                    }
                }

                $order = SalesOrder::create([
                    'order_number' => $this->generateOrderNumber(),
                    'status' => OrderStatus::Draft,
                    'organization_id' => $orgId,
                    'company_id' => $validated['company_id'],
                    'order_date' => $validated['order_date'],
                    'notes' => $validated['notes'] ?? null,
                    'discount_type' => $validated['discount_type'] ?? null,
                    'discount_value' => $validated['discount_value'] ?? 0,
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
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

                    $inventory = Inventory::lockForUpdate()->firstOrCreate(
                        ['product_id' => $item['product_id'], 'warehouse_id' => $item['warehouse_id'], 'organization_id' => $orgId],
                        ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                    );
                    $inventory->increment('reserved_quantity', (float) $item['quantity']);
                }

                $orderDiscountType = $validated['discount_type'] ?? null;
                $orderDiscountValue = (float) ($validated['discount_value'] ?? 0);
                $orderDiscountAmount = match ($orderDiscountType) {
                    'percent' => $subtotal * $orderDiscountValue / 100,
                    'fixed' => min($orderDiscountValue, $subtotal),
                    default => 0,
                };

                $order->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $orderDiscountAmount,
                    'total_amount' => $subtotal + $taxAmount - $orderDiscountAmount,
                ]);

                return $order;
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === '__stock_insufficient__') {
                return response()->json([
                    'message' => 'Tồn kho không đủ cho một số sản phẩm.',
                    'stock_errors' => $stockErrors,
                ], 422);
            }
            throw $e;
        }

        return (new SalesOrderResource($order->load(['company', 'createdBy', 'items.product', 'items.warehouse'])))
            ->response()->setStatusCode(201);
    }

    public function show(SalesOrder $salesOrder): SalesOrderResource
    {
        /** @var User $user */
        $user = Auth::user();
        if (! $user->hasPermission('sales.view_all') && $salesOrder->created_by !== $user->id) {
            abort(403, 'Bạn không có quyền xem đơn hàng này.');
        }

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
            'discount_type' => ['nullable', 'in:percent,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'promotion_id' => ['nullable', 'integer'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_id' => ['required_with:items', 'exists:products,id'],
            'items.*.warehouse_id' => ['required_with:items', 'exists:warehouses,id'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'in:percent,fixed'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $headerData = collect($validated)->except('items')->toArray();

        if (isset($headerData['discount_type']) || isset($headerData['discount_value'])) {
            $discountType = $headerData['discount_type'] ?? $salesOrder->discount_type;
            $discountValue = (float) ($headerData['discount_value'] ?? $salesOrder->discount_value ?? 0);
            $subtotal = (float) $salesOrder->subtotal;
            $headerData['discount_amount'] = match ($discountType) {
                'percent' => $subtotal * $discountValue / 100,
                'fixed' => min($discountValue, $subtotal),
                default => 0,
            };
            $headerData['total_amount'] = $subtotal + (float) $salesOrder->tax_amount - $headerData['discount_amount'];
        }

        $salesOrder->update($headerData);

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

    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate([
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.company_id' => ['required', 'exists:companies,id'],
            'orders.*.order_date' => ['required', 'date'],
            'orders.*.items' => ['required', 'array', 'min:1'],
        ]);

        $orgId = $this->orgId();
        $org = Organization::find($orgId);
        $allowNegativeStock = $org->setting('allow_negative_stock', false);
        $success = 0;
        $errors = [];

        foreach ($request->orders as $i => $orderData) {
            $row = $orderData['_row'] ?? ($i + 2);
            $companyName = $orderData['_company'] ?? "Đơn #{$i}";

            try {
                DB::transaction(function () use ($orderData, $orgId, $allowNegativeStock) {
                    if (! $allowNegativeStock) {
                        foreach ($orderData['items'] as $item) {
                            $inventory = Inventory::where([
                                'product_id' => $item['product_id'],
                                'warehouse_id' => $item['warehouse_id'],
                                'organization_id' => $orgId,
                            ])->lockForUpdate()->first();

                            $available = $inventory ? (float) $inventory->available_quantity : 0;
                            if ((float) $item['quantity'] > $available) {
                                $product = Product::find($item['product_id']);
                                throw new \RuntimeException("Sản phẩm \"{$product?->name}\" không đủ tồn kho (yêu cầu: {$item['quantity']}, khả dụng: {$available})");
                            }
                        }
                    }

                    $order = SalesOrder::create([
                        'order_number' => $this->generateOrderNumber(),
                        'status' => OrderStatus::Draft,
                        'organization_id' => $orgId,
                        'company_id' => $orderData['company_id'],
                        'order_date' => $orderData['order_date'],
                        'notes' => $orderData['notes'] ?? null,
                        'subtotal' => 0,
                        'tax_amount' => 0,
                        'total_amount' => 0,
                        'created_by' => Auth::id(),
                    ]);

                    $subtotal = 0;
                    $taxAmount = 0;

                    foreach ($orderData['items'] as $item) {
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

                        $inventory = Inventory::lockForUpdate()->firstOrCreate(
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
                });
                $success++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $row, 'company' => $companyName, 'reason' => $e->getMessage()];
            }
        }

        return response()->json(['success' => $success, 'failed' => count($errors), 'errors' => $errors]);
    }

    private function generateOrderNumber(): string
    {
        $last = SalesOrder::orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->order_number, 3)) + 1 : 1;

        return 'DH-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
