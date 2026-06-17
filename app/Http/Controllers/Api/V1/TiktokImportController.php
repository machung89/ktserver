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

class TiktokImportController extends Controller
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
            'rows.*.sku' => ['nullable', 'string'],
            'rows.*.product_name' => ['nullable', 'string'],
            'rows.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'rows.*.unit_price' => ['required', 'numeric', 'min:0'],
            'rows.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.subtotal_after_discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $orgId = $this->orgId();

        $warehouse = Warehouse::where('id', $request->warehouse_id)
            ->where('organization_id', $orgId)
            ->first();

        if (! $warehouse) {
            return response()->json(['message' => 'Kho không hợp lệ.'], 422);
        }

        // Pre-load products by code/barcode
        $allSkus = collect($request->rows)
            ->map(fn ($r) => trim($r['sku'] ?? ''))
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

            $existingOrder = SalesOrder::where('ref_id', (string) $orderId)->first();
            if ($existingOrder) {
                // Bỏ qua nếu trạng thái không thay đổi hoặc đã hủy rồi
                if ($existingOrder->status === $mappedStatus || $existingOrder->status === OrderStatus::Cancelled) {
                    continue;
                }

                // Không cho phép downgrade (trừ trường hợp chuyển về Cancelled)
                if ($mappedStatus !== OrderStatus::Cancelled
                    && $this->statusLevel($mappedStatus) <= $this->statusLevel($existingOrder->status)
                ) {
                    continue;
                }

                try {
                    if ($mappedStatus === OrderStatus::Cancelled) {
                        // Huỷ đơn: rollback kho + bút toán nếu đã confirmed/completed
                        $this->salesOrderService->cancel($existingOrder);
                    } else {
                        DB::transaction(function () use ($existingOrder, $mappedStatus) {
                            if ($existingOrder->status === OrderStatus::Draft) {
                                $existingOrder->load('items.product');
                                $this->salesOrderService->confirm($existingOrder);
                            }
                            $existingOrder->update(['status' => $mappedStatus]);
                        });
                    }
                    $updated++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = ['order_id' => $orderId, 'reason' => $e->getMessage()];
                }

                continue;
            }

            if ($mappedStatus === OrderStatus::Cancelled) {
                $cancelled++;

                continue;
            }

            try {
                DB::transaction(function () use ($orderId, $orderRows, $orgId, $warehouse, $productsByCode, $mappedStatus, $firstRow) {
                    $trackingNumber = trim($firstRow['tracking_number'] ?? '') ?: null;

                    $company = Company::where('name', 'Tiktok')
                        ->where('type', CompanyType::Customer)
                        ->first();

                    if (! $company) {
                        $company = Company::create([
                            'organization_id' => $orgId,
                            'name' => 'Tiktok',
                            'type' => CompanyType::Customer,
                            'is_active' => true,
                        ]);
                    }

                    $items = [];
                    $notFound = [];

                    foreach ($orderRows as $row) {
                        $sku = trim($row['sku'] ?? '');

                        $product = $sku && isset($productsByCode[$sku]) ? $productsByCode[$sku] : null;

                        if (! $product) {
                            $notFound[] = $sku ?: '(trống)';

                            continue;
                        }

                        $items[] = [
                            'product_id' => $product->id,
                            'warehouse_id' => $warehouse->id,
                            'quantity' => (float) $row['quantity'],
                            'unit_price' => (float) $row['unit_price'],
                            'cost_price' => (float) ($product->cost_price ?? 0),
                            'standard_price' => (float) ($product->standard_price ?? 0),
                            'discount_pct' => (float) ($row['discount_pct'] ?? 0),
                        ];
                    }

                    $standardTotal = 0;

                    if (! empty($notFound)) {
                        throw new \RuntimeException('Không tìm thấy sản phẩm SKU: '.implode(', ', array_unique($notFound)));
                    }

                    if (empty($items)) {
                        throw new \RuntimeException('Đơn không có sản phẩm hợp lệ.');
                    }

                    $last = SalesOrder::orderByDesc('id')->lockForUpdate()->first();
                    $seq = $last ? ((int) substr($last->order_number, 2)) + 1 : 1;

                    $orderDate = ! empty($firstRow['order_date']) ? $firstRow['order_date'] : now()->toDateString();

                    $order = SalesOrder::create([
                        'order_number' => 'BH'.str_pad($seq, 6, '0', STR_PAD_LEFT),
                        'ref_id' => (string) $orderId,
                        'tracking_number' => $trackingNumber,
                        'status' => OrderStatus::Draft,
                        'organization_id' => $orgId,
                        'company_id' => $company->id,
                        'order_date' => $orderDate,
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
                        $amount = round($base - $discountAmount); // VND: làm tròn về đồng nguyên
                        $subtotal += $amount;
                        $stdPrice = (float) ($item['standard_price'] ?? 0);
                        if ($stdPrice > 0) {
                            $standardTotal += round((float) $item['quantity'] * $stdPrice);
                        }

                        $order->items()->create([
                            'product_id' => $item['product_id'],
                            'warehouse_id' => $item['warehouse_id'],
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'cost_price' => $item['cost_price'],
                            'standard_price' => $item['standard_price'] ?? 0,
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

                    $order->update([
                        'subtotal' => $subtotal,
                        'total_amount' => $subtotal,
                        'standard_total' => $standardTotal,
                        'employee_profit' => $standardTotal > 0 ? $subtotal - $standardTotal : 0,
                    ]);

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
                $errors[] = ['order_id' => $orderId, 'reason' => $e->getMessage()];
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

        if (str_contains($lower, 'cancel') || str_contains($lower, 'hủy') || str_contains($lower, 'huỷ')) {
            return OrderStatus::Cancelled;
        }

        // "Đã giao", "Đã giao hàng", "Hoàn thành", "Delivered", "Completed"
        if (
            str_contains($lower, 'đã giao') ||
            str_contains($lower, 'da giao') ||
            str_contains($lower, 'hoàn thành') ||
            str_contains($lower, 'hoan thanh') ||
            str_contains($lower, 'delivered') ||
            str_contains($lower, 'completed')
        ) {
            return OrderStatus::Completed;
        }

        // "Đang giao hàng" → Shipping
        if (str_contains($lower, 'đang giao') || str_contains($lower, 'out for delivery')) {
            return OrderStatus::Shipping;
        }

        // "Đang trung chuyển", "Đang vận chuyển", "In transit", "Shipped"
        if (
            str_contains($lower, 'trung chuy') ||
            str_contains($lower, 'vận chuyển') ||
            str_contains($lower, 'van chuyen') ||
            str_contains($lower, 'transit') ||
            str_contains($lower, 'shipped')
        ) {
            return OrderStatus::Confirmed;
        }

        // "Đang chờ lấy hàng", default → Nháp
        return OrderStatus::Draft;
    }

    private function statusLevel(OrderStatus $status): int
    {
        return match ($status) {
            OrderStatus::Draft => 0,
            OrderStatus::Confirmed => 1,
            OrderStatus::Shipping => 2,
            OrderStatus::Completed => 3,
            OrderStatus::Cancelled => 4,
        };
    }
}
