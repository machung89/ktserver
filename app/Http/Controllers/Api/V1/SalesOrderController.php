<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SalesOrderItemResource;
use App\Http\Resources\Api\V1\SalesOrderResource;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RestaurantTable;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogService;
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

    public function __construct(
        protected SalesOrderService $salesOrderService,
        protected ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = Auth::user();
        $canViewAll = $user->hasPermission('sales.view_all');
        $createdByFilter = $canViewAll ? null : array_merge([Auth::id()], $user->getViewableUserIds());

        $orders = SalesOrder::with(['company', 'createdBy', 'restaurantTable'])
            ->withExists('warehouseExports')
            ->when($createdByFilter, fn ($q) => $q->whereIn('created_by', $createdByFilter))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->company_id, fn ($q, $v) => $q->where('company_id', $v))
            ->when($request->search, function ($q, $v) {
                $q->where(function ($q) use ($v) {
                    $q->where('order_number', 'like', "%{$v}%")
                        ->orWhere('ref_id', 'like', "%{$v}%")
                        ->orWhere('tracking_number', 'like', "%{$v}%")
                        ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$v}%"));
                });
            })
            ->when($request->date_from, fn ($q, $v) => $q->whereDate('order_date', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->whereDate('order_date', '<=', $v))
            ->when($request->filled('payment_status'), function ($q) use ($request) {
                $statuses = array_filter(explode(',', $request->payment_status));
                if ($statuses) {
                    $q->whereIn('payment_status', $statuses);
                }
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
        $createdByFilter = $canViewAll ? null : array_merge([Auth::id()], $user->getViewableUserIds());
        $base = SalesOrder::when($createdByFilter, fn ($q) => $q->whereIn('created_by', $createdByFilter));

        $counts = (clone $base)->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->getRawOriginal('status') => (int) $r->cnt]);

        $debt = (clone $base)
            ->where('status', 'completed')
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->count();

        return response()->json([
            'all' => (int) (clone $base)->count(),
            'draft' => $counts['draft'] ?? 0,
            'confirmed' => $counts['confirmed'] ?? 0,
            'shipping' => $counts['shipping'] ?? 0,
            'completed' => $counts['completed'] ?? 0,
            'cancelled' => $counts['cancelled'] ?? 0,
            'debt' => (int) $debt,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'exists:companies,id'],
            'restaurant_table_id' => ['nullable', 'exists:restaurant_tables,id'],
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
                $itemProductIds = array_unique(array_column($validated['items'], 'product_id'));
                $recipes = Recipe::with('ingredients')
                    ->whereIn('product_id', $itemProductIds)
                    ->get()
                    ->keyBy('product_id');

                if (! $allowNegativeStock) {
                    // Sản phẩm không có công thức: kiểm tra tồn kho trực tiếp
                    foreach ($validated['items'] as $item) {
                        if ($recipes->has($item['product_id'])) {
                            continue;
                        }
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

                    // Sản phẩm có công thức: kiểm tra nguyên liệu (tổng hợp theo warehouse + ingredient)
                    $ingRequirements = [];
                    foreach ($validated['items'] as $item) {
                        $recipe = $recipes->get($item['product_id']);
                        if (! $recipe) {
                            continue;
                        }
                        $multiplier = (float) $item['quantity'] / (float) $recipe->yield_quantity;
                        foreach ($recipe->ingredients as $ingredient) {
                            $key = "{$item['warehouse_id']}:{$ingredient->ingredient_id}";
                            $ingRequirements[$key]['warehouse_id'] = $item['warehouse_id'];
                            $ingRequirements[$key]['ingredient_id'] = $ingredient->ingredient_id;
                            $ingRequirements[$key]['qty'] = ($ingRequirements[$key]['qty'] ?? 0) + ((float) $ingredient->quantity * $multiplier);
                        }
                    }
                    foreach ($ingRequirements as $req) {
                        $inventory = Inventory::where([
                            'product_id' => $req['ingredient_id'],
                            'warehouse_id' => $req['warehouse_id'],
                            'organization_id' => $orgId,
                        ])->lockForUpdate()->first();

                        $available = $inventory ? (float) $inventory->available_quantity : 0;
                        if ($req['qty'] > $available) {
                            $ingProduct = Product::find($req['ingredient_id']);
                            $warehouse = Warehouse::find($req['warehouse_id']);
                            $stockErrors[] = [
                                'product_id' => $req['ingredient_id'],
                                'warehouse_id' => $req['warehouse_id'],
                                'product_name' => $ingProduct?->name ?? '',
                                'product_code' => $ingProduct?->code ?? '',
                                'warehouse_name' => $warehouse?->name ?? '',
                                'requested' => round($req['qty'], 4),
                                'available' => $available,
                                'shortage' => round($req['qty'] - $available, 4),
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
                    'company_id' => $validated['company_id'] ?? null,
                    'restaurant_table_id' => $validated['restaurant_table_id'] ?? null,
                    'order_date' => $validated['order_date'],
                    'notes' => $validated['notes'] ?? null,
                    'promotion_id' => $validated['promotion_id'] ?? null,
                    'discount_type' => $validated['discount_type'] ?? null,
                    'discount_value' => $validated['discount_value'] ?? 0,
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total_amount' => 0,
                    'created_by' => Auth::id(),
                ]);

                if (! empty($validated['restaurant_table_id'])) {
                    RestaurantTable::where('id', $validated['restaurant_table_id'])
                        ->update(['status' => 'occupied']);
                }

                $subtotal = 0;
                $taxAmount = 0;
                $standardTotal = 0;

                $standardPrices = Product::whereIn('id', array_column($validated['items'], 'product_id'))
                    ->pluck('standard_price', 'id');

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
                    $itemStandardPrice = (float) ($standardPrices[$item['product_id']] ?? 0);
                    if ($itemStandardPrice > 0) {
                        $standardTotal += (float) $item['quantity'] * $itemStandardPrice;
                    } else {
                        $itemStandardPrice = 0;
                    }

                    $order->items()->create([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $item['warehouse_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'discount_type' => $discountType,
                        'discount_value' => $discountValue,
                        'cost_price' => $item['cost_price'] ?? 0,
                        'standard_price' => $itemStandardPrice,
                        'tax_rate' => $taxRate,
                        'amount' => $amount,
                    ]);

                    $recipe = $recipes->get($item['product_id']);
                    if ($recipe) {
                        // Đặt trước nguyên liệu theo công thức
                        $multiplier = (float) $item['quantity'] / (float) $recipe->yield_quantity;
                        foreach ($recipe->ingredients as $ingredient) {
                            $ingQty = round((float) $ingredient->quantity * $multiplier, 4);
                            $inv = Inventory::lockForUpdate()->firstOrCreate(
                                ['product_id' => $ingredient->ingredient_id, 'warehouse_id' => $item['warehouse_id'], 'organization_id' => $orgId],
                                ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                            );
                            $inv->increment('reserved_quantity', $ingQty);
                        }
                    } else {
                        $inventory = Inventory::lockForUpdate()->firstOrCreate(
                            ['product_id' => $item['product_id'], 'warehouse_id' => $item['warehouse_id'], 'organization_id' => $orgId],
                            ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                        );
                        $inventory->increment('reserved_quantity', (float) $item['quantity']);
                    }
                }

                $orderDiscountType = $validated['discount_type'] ?? null;
                $orderDiscountValue = (float) ($validated['discount_value'] ?? 0);
                $orderDiscountAmount = match ($orderDiscountType) {
                    'percent' => $subtotal * $orderDiscountValue / 100,
                    'fixed' => min($orderDiscountValue, $subtotal),
                    default => 0,
                };

                $totalAmount = $subtotal + $taxAmount - $orderDiscountAmount;
                $order->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $orderDiscountAmount,
                    'total_amount' => $totalAmount,
                    'standard_total' => $standardTotal,
                    'employee_profit' => $standardTotal > 0 ? $totalAmount - $standardTotal : 0,
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

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'sales_order_created',
            "Tạo đơn bán {$order->order_number}",
            null, [], 'sales_order', $order->id
        );

        return (new SalesOrderResource($order->load(['company', 'createdBy', 'restaurantTable', 'items.product', 'items.warehouse'])))
            ->response()->setStatusCode(201);
    }

    public function show(SalesOrder $salesOrder): SalesOrderResource
    {
        /** @var User $user */
        $user = Auth::user();
        if (! $user->hasPermission('sales.view_all')) {
            $viewableIds = array_merge([$user->id], $user->getViewableUserIds());
            abort_unless(in_array($salesOrder->created_by, $viewableIds), 403, 'Bạn không có quyền xem đơn hàng này.');
        }

        return new SalesOrderResource($salesOrder->load(['company', 'createdBy', 'restaurantTable', 'items.product', 'items.warehouse', 'payments.account']));
    }

    public function update(Request $request, SalesOrder $salesOrder): SalesOrderResource
    {
        if ($salesOrder->status !== OrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể sửa đơn ở trạng thái nháp.']]);
        }

        $validated = $request->validate([
            'company_id' => ['sometimes', 'nullable', 'exists:companies,id'],
            'restaurant_table_id' => ['sometimes', 'nullable', 'exists:restaurant_tables,id'],
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
            'items.*.is_served' => ['nullable', 'boolean'],
        ]);

        $orgId = $this->orgId();

        DB::transaction(function () use ($salesOrder, $validated, $orgId) {
            $headerData = collect($validated)->except('items')->toArray();
            $salesOrder->update($headerData);

            if (! isset($validated['items'])) {
                return;
            }

            // Load existing items for reservation release
            $salesOrder->load('items');
            $existingProductIds = $salesOrder->items->pluck('product_id')->unique()->all();
            $existingRecipes = Recipe::with('ingredients')
                ->whereIn('product_id', $existingProductIds)
                ->get()
                ->keyBy('product_id');

            // Release reservations for all existing items
            foreach ($salesOrder->items as $item) {
                $recipe = $existingRecipes->get($item->product_id);
                if ($recipe) {
                    $multiplier = (float) $item->quantity / (float) $recipe->yield_quantity;
                    foreach ($recipe->ingredients as $ingredient) {
                        $ingQty = round((float) $ingredient->quantity * $multiplier, 4);
                        Inventory::where([
                            'product_id' => $ingredient->ingredient_id,
                            'warehouse_id' => $item->warehouse_id,
                            'organization_id' => $orgId,
                        ])->decrement('reserved_quantity', $ingQty);
                    }
                } else {
                    Inventory::where([
                        'product_id' => $item->product_id,
                        'warehouse_id' => $item->warehouse_id,
                        'organization_id' => $orgId,
                    ])->decrement('reserved_quantity', (float) $item->quantity);
                }
            }

            // Delete old items
            $salesOrder->items()->delete();

            // Load recipes for new items
            $newProductIds = array_unique(array_column($validated['items'], 'product_id'));
            $newRecipes = Recipe::with('ingredients')
                ->whereIn('product_id', $newProductIds)
                ->get()
                ->keyBy('product_id');

            // Recreate items and reservations
            $subtotal = 0;
            $taxAmount = 0;
            $standardTotal = 0;

            $standardPrices = Product::whereIn('id', array_column($validated['items'], 'product_id'))
                ->pluck('standard_price', 'id');

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
                $itemStandardPrice = (float) ($standardPrices[$item['product_id']] ?? 0);
                if ($itemStandardPrice > 0) {
                    $standardTotal += (float) $item['quantity'] * $itemStandardPrice;
                } else {
                    $itemStandardPrice = 0;
                }

                $salesOrder->items()->create([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $item['warehouse_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'cost_price' => $item['cost_price'] ?? 0,
                    'standard_price' => $itemStandardPrice,
                    'tax_rate' => $taxRate,
                    'amount' => $amount,
                    'is_served' => $item['is_served'] ?? false,
                ]);

                $recipe = $newRecipes->get($item['product_id']);
                if ($recipe) {
                    $multiplier = (float) $item['quantity'] / (float) $recipe->yield_quantity;
                    foreach ($recipe->ingredients as $ingredient) {
                        $ingQty = round((float) $ingredient->quantity * $multiplier, 4);
                        $inv = Inventory::lockForUpdate()->firstOrCreate(
                            ['product_id' => $ingredient->ingredient_id, 'warehouse_id' => $item['warehouse_id'], 'organization_id' => $orgId],
                            ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                        );
                        $inv->increment('reserved_quantity', $ingQty);
                    }
                } else {
                    $inv = Inventory::lockForUpdate()->firstOrCreate(
                        ['product_id' => $item['product_id'], 'warehouse_id' => $item['warehouse_id'], 'organization_id' => $orgId],
                        ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                    );
                    $inv->increment('reserved_quantity', (float) $item['quantity']);
                }
            }

            $orderDiscountType = $salesOrder->discount_type;
            $orderDiscountValue = (float) ($salesOrder->discount_value ?? 0);
            $orderDiscountAmount = match ($orderDiscountType) {
                'percent' => $subtotal * $orderDiscountValue / 100,
                'fixed' => min($orderDiscountValue, $subtotal),
                default => 0,
            };

            $totalAmount = $subtotal + $taxAmount - $orderDiscountAmount;
            $salesOrder->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $orderDiscountAmount,
                'total_amount' => $totalAmount,
                'standard_total' => $standardTotal > 0 ? $standardTotal : null,
                'employee_profit' => $standardTotal > 0 ? $totalAmount - $standardTotal : null,
            ]);
        });

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'sales_order_updated',
            "Cập nhật đơn bán {$salesOrder->order_number}",
            null, [], 'sales_order', $salesOrder->id
        );

        return new SalesOrderResource($salesOrder->fresh()->load(['company', 'restaurantTable', 'items.product', 'items.warehouse']));
    }

    public function confirm(SalesOrder $salesOrder): SalesOrderResource
    {
        if ($salesOrder->status !== OrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể xác nhận đơn ở trạng thái nháp.']]);
        }

        $result = $this->salesOrderService->confirm($salesOrder);

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'sales_order_confirmed',
            "Xác nhận đơn bán {$result->order_number}",
            null, [], 'sales_order', $result->id
        );

        return new SalesOrderResource($result);
    }

    public function ship(SalesOrder $salesOrder): SalesOrderResource
    {
        if ($salesOrder->status !== OrderStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể giao đơn ở trạng thái đã xác nhận.']]);
        }

        $salesOrder->update(['status' => OrderStatus::Shipping]);

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'sales_order_shipped',
            "Chuyển đơn bán {$salesOrder->order_number} sang đang giao",
            null, [], 'sales_order', $salesOrder->id
        );

        return new SalesOrderResource($salesOrder->fresh());
    }

    public function complete(SalesOrder $salesOrder): SalesOrderResource
    {
        if (! in_array($salesOrder->status, [OrderStatus::Confirmed, OrderStatus::Shipping])) {
            throw ValidationException::withMessages(['status' => ['Chỉ có thể hoàn thành đơn ở trạng thái đã xác nhận hoặc đang giao.']]);
        }

        $salesOrder->update(['status' => OrderStatus::Completed]);
        $this->releaseTableIfIdle($salesOrder);

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'sales_order_completed',
            "Hoàn thành đơn bán {$salesOrder->order_number}",
            null, [], 'sales_order', $salesOrder->id
        );

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

        $result = $this->salesOrderService->cancel($salesOrder);
        $this->releaseTableIfIdle($salesOrder);

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'sales_order_cancelled',
            "Hủy đơn bán {$salesOrder->order_number}",
            null, [], 'sales_order', $salesOrder->id
        );

        return new SalesOrderResource($result);
    }

    private function releaseTableIfIdle(SalesOrder $order): void
    {
        if (! $order->restaurant_table_id) {
            return;
        }

        $hasActive = SalesOrder::where('restaurant_table_id', $order->restaurant_table_id)
            ->where('id', '!=', $order->id)
            ->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])
            ->exists();

        if (! $hasActive) {
            RestaurantTable::where('id', $order->restaurant_table_id)
                ->update(['status' => 'available']);
        }
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
                    $itemProductIds = array_unique(array_column($orderData['items'], 'product_id'));
                    $recipes = Recipe::with('ingredients')
                        ->whereIn('product_id', $itemProductIds)
                        ->get()
                        ->keyBy('product_id');

                    if (! $allowNegativeStock) {
                        foreach ($orderData['items'] as $item) {
                            if ($recipes->has($item['product_id'])) {
                                continue;
                            }
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

                        $ingRequirements = [];
                        foreach ($orderData['items'] as $item) {
                            $recipe = $recipes->get($item['product_id']);
                            if (! $recipe) {
                                continue;
                            }
                            $multiplier = (float) $item['quantity'] / (float) $recipe->yield_quantity;
                            foreach ($recipe->ingredients as $ingredient) {
                                $key = "{$item['warehouse_id']}:{$ingredient->ingredient_id}";
                                $ingRequirements[$key]['warehouse_id'] = $item['warehouse_id'];
                                $ingRequirements[$key]['ingredient_id'] = $ingredient->ingredient_id;
                                $ingRequirements[$key]['qty'] = ($ingRequirements[$key]['qty'] ?? 0) + ((float) $ingredient->quantity * $multiplier);
                            }
                        }
                        foreach ($ingRequirements as $req) {
                            $inventory = Inventory::where([
                                'product_id' => $req['ingredient_id'],
                                'warehouse_id' => $req['warehouse_id'],
                                'organization_id' => $orgId,
                            ])->lockForUpdate()->first();

                            $available = $inventory ? (float) $inventory->available_quantity : 0;
                            if ($req['qty'] > $available) {
                                $ingProduct = Product::find($req['ingredient_id']);
                                throw new \RuntimeException("Nguyên liệu \"{$ingProduct?->name}\" không đủ tồn kho (yêu cầu: ".round($req['qty'], 4).", khả dụng: {$available})");
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

                        $recipe = $recipes->get($item['product_id']);
                        if ($recipe) {
                            $multiplier = (float) $item['quantity'] / (float) $recipe->yield_quantity;
                            foreach ($recipe->ingredients as $ingredient) {
                                $ingQty = round((float) $ingredient->quantity * $multiplier, 4);
                                $inv = Inventory::lockForUpdate()->firstOrCreate(
                                    ['product_id' => $ingredient->ingredient_id, 'warehouse_id' => $item['warehouse_id'], 'organization_id' => $orgId],
                                    ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                                );
                                $inv->increment('reserved_quantity', $ingQty);
                            }
                        } else {
                            $inventory = Inventory::lockForUpdate()->firstOrCreate(
                                ['product_id' => $item['product_id'], 'warehouse_id' => $item['warehouse_id'], 'organization_id' => $orgId],
                                ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                            );
                            $inventory->increment('reserved_quantity', (float) $item['quantity']);
                        }
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

    public function updateItem(SalesOrder $salesOrder, SalesOrderItem $salesOrderItem): SalesOrderItemResource
    {
        abort_if($salesOrderItem->sales_order_id !== $salesOrder->id, 404);

        $validated = request()->validate([
            'is_served' => ['required', 'boolean'],
        ]);

        $salesOrderItem->update($validated);

        return new SalesOrderItemResource($salesOrderItem->load(['product', 'warehouse']));
    }

    private function generateOrderNumber(): string
    {
        $last = SalesOrder::orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->order_number, 2)) + 1 : 1;

        return 'BH'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
