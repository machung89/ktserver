<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Services\InventoryTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentController extends Controller
{
    use ScopedByOrganization;

    public function __construct(private readonly InventoryTransactionService $inventoryService) {}

    public function index(Request $request): JsonResponse
    {
        $query = InventoryTransaction::with(['warehouse', 'items.product'])
            ->where('type', TransactionType::Adjustment)
            ->when($request->warehouse_id, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        $paginated = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $paginated->getCollection()->map(fn ($t) => $this->format($t)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'transaction_date' => 'required|date',
            'description' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity_delta' => 'required|numeric|not_in:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        $orgId = $this->orgId();
        $warehouseId = $validated['warehouse_id'];

        $transaction = DB::transaction(function () use ($validated, $orgId, $warehouseId) {
            $tx = InventoryTransaction::create([
                'type' => TransactionType::Adjustment,
                'warehouse_id' => $warehouseId,
                'transaction_date' => $validated['transaction_date'],
                'description' => $validated['description'] ?? null,
                'is_posted' => true,
                'organization_id' => $orgId,
            ]);

            foreach ($validated['items'] as $item) {
                $delta = (float) $item['quantity_delta'];
                $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0;

                if ($delta < 0 && $unitPrice === 0.0) {
                    $inv = Inventory::where('warehouse_id', $warehouseId)
                        ->where('product_id', $item['product_id'])
                        ->lockForUpdate()
                        ->first();
                    $unitPrice = $inv ? (float) $inv->avg_cost : 0.0;
                }

                $tx->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $delta,
                    'unit_price' => $unitPrice,
                ]);

                $this->inventoryService->updateInventoryBalance($warehouseId, $item['product_id'], $delta, $unitPrice);
            }

            return $tx->load(['warehouse', 'items.product']);
        });

        return response()->json($this->format($transaction), 201);
    }

    public function show(InventoryTransaction $adjustment): JsonResponse
    {
        abort_if($adjustment->type !== TransactionType::Adjustment, 404);

        return response()->json($this->format($adjustment->load(['warehouse', 'items.product'])));
    }

    public function destroy(InventoryTransaction $adjustment): JsonResponse
    {
        abort_if($adjustment->type !== TransactionType::Adjustment, 404);

        DB::transaction(function () use ($adjustment) {
            $adjustment->load('items');

            foreach ($adjustment->items as $item) {
                $this->inventoryService->updateInventoryBalance(
                    $adjustment->warehouse_id,
                    $item->product_id,
                    -(float) $item->quantity,
                    (float) $item->unit_price
                );
            }

            $adjustment->delete();
        });

        return response()->json(['message' => 'Đã hủy phiếu điều chỉnh.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(InventoryTransaction $tx): array
    {
        return [
            'id' => $tx->id,
            'warehouse_id' => $tx->warehouse_id,
            'warehouse' => $tx->warehouse?->name,
            'transaction_date' => $tx->transaction_date?->toDateString(),
            'description' => $tx->description,
            'items' => $tx->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product?->code,
                'product_name' => $item->product?->name,
                'unit' => $item->product?->unit,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
            ]),
            'created_at' => $tx->created_at?->toDateTimeString(),
        ];
    }
}
