<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\InventoryResource;
use App\Models\Inventory;
use App\Models\InventoryTransactionItem;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    use ScopedByOrganization;

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

        return InventoryResource::collection($inventory);
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
