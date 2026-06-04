<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\InventoryResource;
use App\Models\Inventory;
use App\Models\InventoryTransactionItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    use ScopedByOrganization;

    /** Số ngày bán hết mặc định để cảnh báo sắp hết (có thể chỉnh qua cài đặt tổ chức) */
    private const DEFAULT_COVER_DAYS = 7;

    /** Số ngày lịch sử dùng tính tốc độ bán trung bình */
    private const VELOCITY_WINDOW = 30;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Inventory::with(['product.category', 'warehouse'])
            ->when($request->warehouse_id, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->product_id, fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->boolean('low_stock', false), fn ($q) => $q->whereColumn('quantity', '<=', 'min_quantity'))
            ->when($request->search, fn ($q, $v) => $q->whereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%")))
            ->when($request->category_id, fn ($q, $v) => $q->whereHas('product', fn ($pq) => $pq->where('category_id', $v)));

        $perPage = $request->filled('per_page') ? (int) $request->per_page : 50;

        $inventory = $query->paginate($perPage);

        $this->attachSalesVelocity($inventory->getCollection());

        return InventoryResource::collection($inventory);
    }

    /**
     * Gắn tốc độ bán & số ngày tồn còn lại (days_of_cover) cho từng dòng tồn kho.
     * Dùng 1 truy vấn gộp lấy số bán {VELOCITY_WINDOW} ngày của các SP trên trang — không N+1.
     *
     * @param  Collection<int, Inventory>  $items
     */
    private function attachSalesVelocity($items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $orgId = $this->orgId();
        $productIds = $items->pluck('product_id')->unique()->all();
        $from = now()->subDays(self::VELOCITY_WINDOW)->toDateString();

        $soldMap = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->where('so.order_date', '>=', $from)
            ->where('soi.is_return', false)
            ->whereIn('soi.product_id', $productIds)
            ->groupBy('soi.product_id')
            ->selectRaw('soi.product_id, SUM(soi.quantity) as sold')
            ->pluck('sold', 'soi.product_id');

        $coverDays = (int) (Organization::find($orgId)?->setting('low_stock_cover_days', self::DEFAULT_COVER_DAYS) ?? self::DEFAULT_COVER_DAYS);

        foreach ($items as $inv) {
            $velocity = (float) ($soldMap[$inv->product_id] ?? 0) / self::VELOCITY_WINDOW;
            $available = (float) $inv->available_quantity;

            $inv->sales_velocity = round($velocity, 3);
            $inv->days_of_cover = $velocity > 0 ? round($available / $velocity, 1) : null;
            $inv->low_stock_cover_days = $coverDays;
        }
    }

    public function byProduct(Product $product): AnonymousResourceCollection
    {
        return InventoryResource::collection(
            Inventory::with('warehouse')->where('product_id', $product->id)->get()
        );
    }

    public function byWarehouse(Warehouse $warehouse): AnonymousResourceCollection
    {
        return InventoryResource::collection(
            Inventory::with('product')->where('warehouse_id', $warehouse->id)->get()
        );
    }

    public function productHistory(Request $request, Product $product): JsonResponse
    {
        $query = InventoryTransactionItem::with(['inventoryTransaction.warehouse'])
            ->where('product_id', $product->id)
            ->whereHas('inventoryTransaction', function ($q) use ($request) {
                $q->where('organization_id', app('orgId'));
                if ($request->filled('warehouse_id')) {
                    $q->where('warehouse_id', $request->warehouse_id);
                }
                if ($request->filled('type')) {
                    $q->where('type', $request->type);
                }
            })
            ->orderByDesc(
                \DB::raw('(SELECT transaction_date FROM inventory_transactions WHERE id = inventory_transaction_items.inventory_transaction_id)')
            )
            ->orderByDesc('id');

        $items = $query->paginate($request->filled('per_page') ? (int) $request->per_page : 50);

        return response()->json([
            'data' => $items->getCollection()->map(function (InventoryTransactionItem $item) {
                $tx = $item->inventoryTransaction;

                return [
                    'id' => $item->id,
                    'transaction_date' => $tx->transaction_date,
                    'type' => $tx->type,
                    'warehouse' => $tx->warehouse?->name,
                    'warehouse_id' => $tx->warehouse_id,
                    'description' => $tx->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => round((float) $item->quantity * (float) $item->unit_price, 2),
                ];
            }),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }
}
