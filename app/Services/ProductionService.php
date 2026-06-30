<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected InventoryTransactionService $inventoryTransactionService,
    ) {}

    /**
     * Hoàn thành lệnh sản xuất: xuất NVL → tập hợp chi phí (621/622/627) → kết chuyển 154
     * → nhập kho thành phẩm (giá thành = NVL + nhân công + SX chung).
     */
    public function complete(ProductionOrder $order): ProductionOrder
    {
        // Guard NGOÀI transaction (tránh lỗi savepoint khi ValidationException ném trong nested transaction)
        $order->loadMissing('materials');

        if ($order->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Chỉ hoàn thành được lệnh ở trạng thái nháp.']]);
        }
        if ((float) $order->quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Số lượng sản xuất phải lớn hơn 0.']]);
        }

        $org = Organization::find(app('orgId'));
        if (! $org->setting('allow_negative_stock', false)) {
            $this->assertMaterialsAvailable($order);
        }

        return DB::transaction(function () use ($order) {
            $order = ProductionOrder::with(['materials', 'costs'])->lockForUpdate()->find($order->id);

            // Khóa lại & xác minh trạng thái (chống race-condition hoàn thành 2 lần)
            if ($order->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Lệnh đã được xử lý.']]);
            }

            $orgId = app('orgId');
            $warehouseId = $order->warehouse_id;

            // 1) Xuất NVL theo giá vốn bình quân hiện tại
            $materialCost = 0.0;
            $consumeItems = [];
            foreach ($order->materials as $m) {
                $avgCost = (float) (Inventory::where([
                    'organization_id' => $orgId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $m->product_id,
                ])->value('avg_cost') ?? 0);

                $qty = (float) $m->quantity;
                $amount = round($qty * $avgCost, 2);
                $materialCost += $amount;
                $m->update(['unit_cost' => $avgCost, 'amount' => $amount]);

                $consumeItems[] = ['product_id' => $m->product_id, 'quantity' => -$qty, 'unit_price' => $avgCost];
            }
            $materialCost = round($materialCost, 2);

            if ($consumeItems) {
                $this->inventoryTransactionService->post(
                    type: TransactionType::ProductionConsumption,
                    warehouseId: $warehouseId,
                    date: $order->production_date->toDateString(),
                    reference: $order,
                    items: $consumeItems,
                    description: "Xuất NVL sản xuất - {$order->production_number}",
                );
            }

            // 2) Chi phí nhân công (622) / SX chung (627)
            $laborCost = round((float) $order->costs->where('type', 'labor')->sum('amount'), 2);
            $overheadCost = round((float) $order->costs->where('type', 'overhead')->sum('amount'), 2);
            $totalCost = round($materialCost + $laborCost + $overheadCost, 2);
            $qtyProduced = (float) $order->quantity;
            // Đơn giá CHÍNH XÁC để stock_value tăng đúng = tổng giá thành (bất biến stock_value = TK 156).
            // unit_cost lưu trên lệnh là giá làm tròn để hiển thị.
            $preciseUnit = $qtyProduced > 0 ? $totalCost / $qtyProduced : 0;
            $unitCost = round($preciseUnit, 2);

            // 3) Nhập kho thành phẩm theo giá thành
            $this->inventoryTransactionService->post(
                type: TransactionType::ProductionReceipt,
                warehouseId: $warehouseId,
                date: $order->production_date->toDateString(),
                reference: $order,
                items: [['product_id' => $order->product_id, 'quantity' => $qtyProduced, 'unit_price' => $preciseUnit]],
                description: "Nhập kho thành phẩm - {$order->production_number}",
            );

            // 4) Bút toán VAS
            $this->postJournal($order, $materialCost, $laborCost, $overheadCost, $totalCost);

            $order->update([
                'status' => 'completed',
                'material_cost' => $materialCost,
                'labor_cost' => $laborCost,
                'overhead_cost' => $overheadCost,
                'total_cost' => $totalCost,
                'unit_cost' => $unitCost,
            ]);

            return $order->fresh(['product', 'warehouse', 'materials.product', 'costs']);
        });
    }

    /**
     * Hủy lệnh đã hoàn thành: đảo nhập kho thành phẩm + nhập lại NVL, xóa bút toán.
     */
    public function cancel(ProductionOrder $order): ProductionOrder
    {
        // Guard NGOÀI transaction (tránh lỗi savepoint khi ValidationException ném trong nested transaction)
        if ($order->status !== 'completed') {
            throw ValidationException::withMessages(['status' => ['Chỉ hủy được lệnh đã hoàn thành.']]);
        }

        // Thành phẩm phải còn đủ trong kho mới đảo được (chưa bị bán/sử dụng)
        $finishedQty = (float) (Inventory::where([
            'organization_id' => app('orgId'),
            'warehouse_id' => $order->warehouse_id,
            'product_id' => $order->product_id,
        ])->value('quantity') ?? 0);

        if ($finishedQty < (float) $order->quantity) {
            throw ValidationException::withMessages(['status' => ['Thành phẩm đã được bán/sử dụng — không thể hủy lệnh sản xuất.']]);
        }

        return DB::transaction(function () use ($order) {
            $order = ProductionOrder::with(['materials'])->lockForUpdate()->find($order->id);

            // Khóa lại & xác minh trạng thái (chống race-condition)
            if ($order->status !== 'completed') {
                throw ValidationException::withMessages(['status' => ['Lệnh đã được xử lý.']]);
            }

            $warehouseId = $order->warehouse_id;

            // Đảo nhập kho thành phẩm: xuất thành phẩm ra (đơn giá chính xác để khớp giá trị đã nhập)
            $qty = (float) $order->quantity;
            $preciseUnit = $qty > 0 ? (float) $order->total_cost / $qty : 0;
            $this->inventoryTransactionService->post(
                type: TransactionType::ProductionReceipt,
                warehouseId: $warehouseId,
                date: now()->toDateString(),
                reference: $order,
                items: [['product_id' => $order->product_id, 'quantity' => -$qty, 'unit_price' => $preciseUnit]],
                description: "Hủy SX - xuất thành phẩm - {$order->production_number}",
            );

            // Nhập lại NVL theo giá đã xuất
            $returnItems = [];
            foreach ($order->materials as $m) {
                $returnItems[] = ['product_id' => $m->product_id, 'quantity' => (float) $m->quantity, 'unit_price' => (float) $m->unit_cost];
            }
            if ($returnItems) {
                $this->inventoryTransactionService->post(
                    type: TransactionType::ProductionConsumption,
                    warehouseId: $warehouseId,
                    date: now()->toDateString(),
                    reference: $order,
                    items: $returnItems,
                    description: "Hủy SX - nhập lại NVL - {$order->production_number}",
                );
            }

            $this->journalEntryService->deleteByReference($order);

            $order->update(['status' => 'cancelled']);

            return $order->fresh(['product', 'warehouse', 'materials.product', 'costs']);
        });
    }

    /**
     * Kiểm tra đủ tồn nguyên vật liệu (gộp theo sản phẩm, cùng kho của lệnh).
     */
    private function assertMaterialsAvailable(ProductionOrder $order): void
    {
        $orgId = app('orgId');
        $required = [];
        foreach ($order->materials as $m) {
            $required[$m->product_id] = ($required[$m->product_id] ?? 0) + (float) $m->quantity;
        }

        $errors = [];
        foreach ($required as $productId => $qty) {
            $physical = (float) (Inventory::where([
                'organization_id' => $orgId,
                'warehouse_id' => $order->warehouse_id,
                'product_id' => $productId,
            ])->value('quantity') ?? 0);

            if ($physical < $qty) {
                $name = Product::where('id', $productId)->value('name') ?? "NL #{$productId}";
                $errors[] = "Nguyên liệu \"{$name}\" không đủ tồn (cần ".round($qty, 4).", hiện có {$physical}).";
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages(['inventory' => $errors]);
        }
    }

    /**
     * Bút toán VAS: 621/622/627 (tập hợp) → 154 (kết chuyển) → 156 (nhập kho thành phẩm).
     * NVL & thành phẩm theo dõi trên TK 156 (đồng nhất với toàn hệ thống tồn kho).
     */
    private function postJournal(ProductionOrder $order, float $materialCost, float $laborCost, float $overheadCost, float $totalCost): void
    {
        if ($totalCost <= 0) {
            return;
        }

        $no = $order->production_number;
        $lines = [];

        // Tập hợp chi phí NVL trực tiếp
        if ($materialCost > 0) {
            $lines[] = ['account_code' => '621', 'description' => "CP NVL trực tiếp - {$no}", 'debit' => $materialCost, 'credit' => 0];
            $lines[] = ['account_code' => '156', 'description' => "Xuất NVL sản xuất - {$no}", 'debit' => 0, 'credit' => $materialCost];
        }

        // Tập hợp nhân công / SX chung theo từng khoản (TK đối ứng tự chọn)
        foreach ($order->costs as $c) {
            $amt = round((float) $c->amount, 2);
            if ($amt <= 0) {
                continue;
            }
            $debitCode = $c->type === 'labor' ? '622' : '627';
            $lines[] = ['account_code' => $debitCode, 'description' => "{$c->name} - {$no}", 'debit' => $amt, 'credit' => 0];
            $lines[] = ['account_code' => $c->credit_account_code, 'description' => "{$c->name} - {$no}", 'debit' => 0, 'credit' => $amt];
        }

        // Kết chuyển vào 154 (CPSXKD dở dang)
        foreach ([['621', $materialCost, 'CP NVL'], ['622', $laborCost, 'CP nhân công'], ['627', $overheadCost, 'CP SX chung']] as [$code, $amount, $label]) {
            if ($amount > 0) {
                $lines[] = ['account_code' => '154', 'description' => "Kết chuyển {$label} - {$no}", 'debit' => $amount, 'credit' => 0];
                $lines[] = ['account_code' => $code, 'description' => "Kết chuyển {$label} - {$no}", 'debit' => 0, 'credit' => $amount];
            }
        }

        // Nhập kho thành phẩm
        $lines[] = ['account_code' => '156', 'description' => "Nhập kho thành phẩm - {$no}", 'debit' => $totalCost, 'credit' => 0];
        $lines[] = ['account_code' => '154', 'description' => "Nhập kho thành phẩm - {$no}", 'debit' => 0, 'credit' => $totalCost];

        $this->journalEntryService->create(
            description: "Sản xuất nhập kho - {$no}",
            entryDate: $order->production_date->toDateString(),
            reference: $order,
            lines: $lines,
        );
    }
}
