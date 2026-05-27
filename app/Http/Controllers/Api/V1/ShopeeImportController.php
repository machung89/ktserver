<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShopeeImportController extends Controller
{
    use ScopedByOrganization;

    public function __construct(protected SalesOrderService $salesOrderService) {}

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.order_id' => ['required', 'string'],
            'rows.*.order_date' => ['nullable', 'date'],
            'rows.*.status' => ['nullable', 'string'],
            'rows.*.buyer' => ['nullable', 'string'],
            'rows.*.shipping_address' => ['nullable', 'string'],
            'rows.*.variation_sku' => ['nullable', 'string'],
            'rows.*.sku' => ['nullable', 'string'],
            'rows.*.product_name' => ['nullable', 'string'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'rows.*.unit_price' => ['required', 'numeric', 'min:0'],
            'rows.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $orgId = $this->orgId();

        $warehouse = Warehouse::where('id', $request->warehouse_id)
            ->where('organization_id', $orgId)
            ->first();

        if (! $warehouse) {
            return response()->json(['message' => 'Kho không hợp lệ.'], 422);
        }

        // Pre-load products by code and barcode for fast lookup
        $allSkus = collect($request->rows)
            ->flatMap(fn ($r) => array_filter([trim($r['variation_sku'] ?? ''), trim($r['sku'] ?? '')]))
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var array<string, Product> $productsByCode */
        $productsByCode = [];
        if (! empty($allSkus)) {
            $products = Product::where(fn ($q) => $q->whereIn('code', $allSkus)->orWhereIn('barcode', $allSkus))->get();
            foreach ($products as $product) {
                if ($product->code && in_array($product->code, $allSkus, true)) {
                    $productsByCode[$product->code] = $product;
                }
                if ($product->barcode && in_array($product->barcode, $allSkus, true)) {
                    $productsByCode[$product->barcode] = $product;
                }
            }
        }

        $groupedByOrder = collect($request->rows)->groupBy('order_id');

        $success = 0;
        $updated = 0;
        $failed = 0;
        $cancelled = 0;
        $errors = [];

        foreach ($groupedByOrder as $orderId => $orderRows) {
            $firstRow = $orderRows->first();
            $mappedStatus = $this->resolveStatus($firstRow['status'] ?? '');

            // If order already exists by ref_id → confirm if transitioning from Draft, then update status
            $existingOrder = SalesOrder::where('ref_id', (string) $orderId)->first();
            if ($existingOrder) {
                try {
                    DB::transaction(function () use ($existingOrder, $mappedStatus) {
                        if ($existingOrder->status === OrderStatus::Draft && $mappedStatus !== OrderStatus::Draft) {
                            $existingOrder->load('items.product');
                            $this->salesOrderService->confirm($existingOrder);
                        }

                        if ($mappedStatus !== $existingOrder->status) {
                            $existingOrder->update(['status' => $mappedStatus]);
                        }
                    });
                    $updated++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'order_id' => $orderId,
                        'buyer' => trim($firstRow['buyer'] ?? ''),
                        'reason' => $e->getMessage(),
                    ];
                }

                continue;
            }

            // New order: skip if cancelled
            if ($mappedStatus === OrderStatus::Cancelled) {
                $cancelled++;

                continue;
            }

            try {
                DB::transaction(function () use ($orderId, $orderRows, $orgId, $warehouse, $productsByCode, $mappedStatus, $firstRow) {
                    $buyerName = trim($firstRow['buyer'] ?? '') ?: 'Khách Shopee';
                    $shippingAddress = trim($firstRow['shipping_address'] ?? '') ?: null;
                    $trackingNumber = trim($firstRow['tracking_number'] ?? '') ?: null;

                    $company = Company::where('name', $buyerName)
                        ->where('type', CompanyType::Customer)
                        ->first();

                    if (! $company) {
                        $company = Company::create([
                            'organization_id' => $orgId,
                            'name' => $buyerName,
                            'address' => $shippingAddress,
                            'type' => CompanyType::Customer,
                            'is_active' => true,
                        ]);
                    }

                    $items = [];
                    $notFound = [];

                    foreach ($orderRows as $row) {
                        $variationSku = trim($row['variation_sku'] ?? '');
                        $sku = trim($row['sku'] ?? '');

                        $product = null;
                        foreach (array_filter([$variationSku, $sku]) as $candidate) {
                            if (isset($productsByCode[$candidate])) {
                                $product = $productsByCode[$candidate];
                                break;
                            }
                        }

                        if (! $product) {
                            $notFound[] = $variationSku ?: $sku ?: '(trống)';

                            continue;
                        }

                        $items[] = [
                            'product_id' => $product->id,
                            'warehouse_id' => $warehouse->id,
                            'quantity' => (float) $row['quantity'],
                            'unit_price' => (float) $row['unit_price'],
                            'cost_price' => (float) ($product->cost_price ?? 0),
                            'discount_pct' => (float) ($row['discount_pct'] ?? 0),
                        ];
                    }

                    if (! empty($notFound)) {
                        throw new \RuntimeException('Không tìm thấy sản phẩm SKU: '.implode(', ', array_unique($notFound)));
                    }

                    if (empty($items)) {
                        throw new \RuntimeException('Đơn không có sản phẩm hợp lệ.');
                    }

                    $last = SalesOrder::orderByDesc('id')->lockForUpdate()->first();
                    $seq = $last ? ((int) substr($last->order_number, 2)) + 1 : 1;

                    $order = SalesOrder::create([
                        'order_number' => 'BH'.str_pad($seq, 6, '0', STR_PAD_LEFT),
                        'ref_id' => (string) $orderId,
                        'tracking_number' => $trackingNumber,
                        'status' => OrderStatus::Draft,
                        'organization_id' => $orgId,
                        'company_id' => $company->id,
                        'order_date' => ! empty($firstRow['order_date']) ? $firstRow['order_date'] : now()->toDateString(),
                        'notes' => null,
                        'subtotal' => 0,
                        'tax_amount' => 0,
                        'total_amount' => 0,
                        'created_by' => Auth::id(),
                    ]);

                    $subtotal = 0;
                    foreach ($items as $item) {
                        $base = (float) $item['quantity'] * (float) $item['unit_price'];
                        $discountPct = (float) ($item['discount_pct'] ?? 0);
                        $discountAmount = $base * $discountPct / 100;
                        $amount = $base - $discountAmount;
                        $subtotal += $amount;

                        $order->items()->create([
                            'product_id' => $item['product_id'],
                            'warehouse_id' => $item['warehouse_id'],
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'cost_price' => $item['cost_price'],
                            'tax_rate' => 0,
                            'discount_type' => $discountPct > 0 ? 'percent' : null,
                            'discount_value' => $discountPct,
                            'amount' => $amount,
                        ]);

                        Inventory::lockForUpdate()->firstOrCreate(
                            ['product_id' => $item['product_id'], 'warehouse_id' => $item['warehouse_id'], 'organization_id' => $orgId],
                            ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
                        )->increment('reserved_quantity', (float) $item['quantity']);
                    }

                    $order->update(['subtotal' => $subtotal, 'total_amount' => $subtotal]);

                    if ($mappedStatus !== OrderStatus::Draft) {
                        $order->load('items.product');
                        $this->salesOrderService->confirm($order);
                        if (in_array($mappedStatus, [OrderStatus::Shipping, OrderStatus::Completed], true)) {
                            $order->update(['status' => $mappedStatus]);
                        }
                    }
                });

                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'order_id' => $orderId,
                    'buyer' => trim($firstRow['buyer'] ?? ''),
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => $success,
            'updated' => $updated,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'errors' => $errors,
        ]);
    }

    private function resolveStatus(string $statusText): OrderStatus
    {
        $lower = mb_strtolower(trim($statusText));

        if (str_contains($lower, 'hủy')) {
            return OrderStatus::Cancelled;
        }
        if (str_contains($lower, 'đã giao') || str_contains($lower, 'giao hàng thành công') || str_contains($lower, 'hoàn thành')) {
            return OrderStatus::Completed;
        }
        if (str_contains($lower, 'đang giao') || str_contains($lower, 'chờ lấy hàng') || str_contains($lower, 'đã lấy hàng')) {
            return OrderStatus::Shipping;
        }
        if (str_contains($lower, 'xác nhận') || str_contains($lower, 'đang xử lý')) {
            return OrderStatus::Confirmed;
        }

        return OrderStatus::Draft;
    }
}
