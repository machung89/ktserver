<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Services\ProductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductionOrderController extends Controller
{
    use ScopedByOrganization;

    public function __construct(protected ProductionService $productionService) {}

    public function index(Request $request): JsonResponse
    {
        $orders = ProductionOrder::with(['product', 'warehouse', 'createdBy'])
            ->when($request->status, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->from, fn ($q, $v) => $q->where('production_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('production_date', '<=', $v))
            ->when($request->search, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('production_number', 'like', "%{$v}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%"))))
            ->orderByDesc('id')
            ->paginate($request->filled('per_page') ? (int) $request->per_page : 20);

        return response()->json($orders);
    }

    public function show(ProductionOrder $productionOrder): JsonResponse
    {
        return response()->json([
            'data' => $productionOrder->load(['product', 'warehouse', 'createdBy', 'materials.product', 'costs']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $order = DB::transaction(function () use ($validated) {
            $order = ProductionOrder::create([
                'organization_id' => $this->orgId(),
                'production_number' => $this->generateNumber(),
                'product_id' => $validated['product_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'quantity' => $validated['quantity'],
                'status' => 'draft',
                'production_date' => $validated['production_date'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $this->syncMaterials($order, $validated);
            $this->syncCosts($order, $validated['costs'] ?? []);

            return $order;
        });

        return response()->json(['data' => $order->load(['product', 'warehouse', 'materials.product', 'costs'])], 201);
    }

    public function update(Request $request, ProductionOrder $productionOrder): JsonResponse
    {
        if ($productionOrder->status !== 'draft') {
            return response()->json(['message' => 'Chỉ sửa được lệnh ở trạng thái nháp.'], 422);
        }

        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($productionOrder, $validated) {
            $productionOrder->update([
                'product_id' => $validated['product_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'quantity' => $validated['quantity'],
                'production_date' => $validated['production_date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $productionOrder->materials()->delete();
            $productionOrder->costs()->delete();
            $this->syncMaterials($productionOrder, $validated);
            $this->syncCosts($productionOrder, $validated['costs'] ?? []);
        });

        return response()->json(['data' => $productionOrder->fresh(['product', 'warehouse', 'materials.product', 'costs'])]);
    }

    public function complete(ProductionOrder $productionOrder): JsonResponse
    {
        $order = $this->productionService->complete($productionOrder);

        return response()->json(['data' => $order]);
    }

    public function cancel(ProductionOrder $productionOrder): JsonResponse
    {
        $order = $this->productionService->cancel($productionOrder);

        return response()->json(['data' => $order]);
    }

    public function destroy(ProductionOrder $productionOrder): JsonResponse
    {
        if ($productionOrder->status !== 'draft') {
            return response()->json(['message' => 'Chỉ xóa được lệnh ở trạng thái nháp.'], 422);
        }

        $productionOrder->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'production_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'materials' => ['nullable', 'array'],
            'materials.*.product_id' => ['required_with:materials', 'exists:products,id'],
            'materials.*.quantity' => ['required_with:materials', 'numeric', 'min:0.0001'],
            'costs' => ['nullable', 'array'],
            'costs.*.type' => ['required_with:costs', 'in:labor,overhead'],
            'costs.*.name' => ['required_with:costs', 'string', 'max:255'],
            'costs.*.amount' => ['required_with:costs', 'numeric', 'min:0'],
            'costs.*.credit_account_code' => ['required_with:costs', 'exists:accounts,code'],
        ]);
    }

    /**
     * Tạo dòng NVL: ưu tiên dữ liệu gửi lên; nếu không có thì lấy theo công thức (BOM) của thành phẩm × số lượng.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncMaterials(ProductionOrder $order, array $validated): void
    {
        $materials = $validated['materials'] ?? null;

        if (empty($materials)) {
            $recipe = Recipe::with('ingredients')->where('product_id', $validated['product_id'])->first();
            if ($recipe && (float) $recipe->yield_quantity > 0) {
                $multiplier = (float) $validated['quantity'] / (float) $recipe->yield_quantity;
                $materials = $recipe->ingredients->map(fn ($ing) => [
                    'product_id' => $ing->ingredient_id,
                    'quantity' => round((float) $ing->quantity * $multiplier, 4),
                ])->all();
            }
        }

        foreach ($materials ?? [] as $m) {
            $order->materials()->create([
                'product_id' => $m['product_id'],
                'quantity' => $m['quantity'],
                'unit_cost' => 0,
                'amount' => 0,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $costs
     */
    private function syncCosts(ProductionOrder $order, array $costs): void
    {
        foreach ($costs as $c) {
            $order->costs()->create([
                'type' => $c['type'],
                'name' => $c['name'],
                'amount' => $c['amount'],
                'credit_account_code' => $c['credit_account_code'],
            ]);
        }
    }

    private function generateNumber(): string
    {
        $last = ProductionOrder::orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->production_number, 2)) + 1 : 1;

        return 'SX'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
