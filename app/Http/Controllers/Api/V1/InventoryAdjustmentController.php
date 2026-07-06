<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Services\InventoryTransactionService;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentController extends Controller
{
    use ScopedByOrganization;

    public function __construct(
        private readonly InventoryTransactionService $inventoryService,
        private readonly JournalEntryService $journalService,
    ) {}

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

            $increaseValue = 0.0; // giá trị tăng tồn (Nợ 156 / Có 711)
            $decreaseValue = 0.0; // giá trị giảm tồn (Nợ 632 / Có 156)

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

                // Giá trị luân chuyển = Δ × đơn giá (khớp thay đổi stock_value → TK 156)
                $value = round($delta * $unitPrice, 2);
                if ($value > 0) {
                    $increaseValue += $value;
                } elseif ($value < 0) {
                    $decreaseValue += -$value;
                }
            }

            $this->postJournal($tx, round($increaseValue, 2), round($decreaseValue, 2));

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

            // Đảo bút toán điều chỉnh
            $this->journalService->deleteByReference($adjustment);

            $adjustment->delete();
        });

        return response()->json(['message' => 'Đã hủy phiếu điều chỉnh.']);
    }

    /**
     * Bút toán điều chỉnh tồn kho — giữ TK 156 khớp với giá trị tồn thực.
     * Tăng (thừa): Nợ 156 / Có 711. Giảm (hao hụt): Nợ 632 / Có 156.
     */
    private function postJournal(InventoryTransaction $tx, float $increaseValue, float $decreaseValue): void
    {
        if ($increaseValue < 1 && $decreaseValue < 1) {
            return; // không có giá trị luân chuyển (đơn giá 0) → bỏ qua
        }

        $desc = 'Điều chỉnh tồn kho'.($tx->description ? " - {$tx->description}" : '');
        $lines = [];

        if ($increaseValue >= 1) {
            $lines[] = ['account_code' => '156', 'description' => "Tồn thừa kiểm kê - {$desc}", 'debit' => $increaseValue, 'credit' => 0];
            $lines[] = ['account_code' => '711', 'description' => "Thu nhập hàng thừa - {$desc}", 'debit' => 0, 'credit' => $increaseValue];
        }

        if ($decreaseValue >= 1) {
            $lines[] = ['account_code' => '632', 'description' => "Hao hụt/giảm tồn - {$desc}", 'debit' => $decreaseValue, 'credit' => 0];
            $lines[] = ['account_code' => '156', 'description' => "Tồn thiếu kiểm kê - {$desc}", 'debit' => 0, 'credit' => $decreaseValue];
        }

        $this->journalService->create(
            description: $desc,
            entryDate: $tx->transaction_date->toDateString(),
            reference: $tx,
            lines: $lines,
        );
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
