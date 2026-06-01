<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\CompanyType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Tài khoản kế toán cần cho bút toán tự động
        foreach ([
            ['code' => '131', 'name' => 'Phải thu khách hàng',   'type' => AccountType::Asset],
            ['code' => '156', 'name' => 'Hàng hóa',              'type' => AccountType::Asset],
            ['code' => '331', 'name' => 'Phải trả nhà cung cấp', 'type' => AccountType::Liability],
            ['code' => '511', 'name' => 'Doanh thu bán hàng',    'type' => AccountType::Revenue],
            ['code' => '632', 'name' => 'Giá vốn hàng bán',     'type' => AccountType::Expense],
        ] as $acc) {
            Account::create(array_merge($acc, ['organization_id' => $this->organization->id, 'is_active' => true]));
        }

        $this->warehouse = Warehouse::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'cost_price' => 80000,
            'is_active' => true,
        ]);
    }

    private function getInventory(): ?Inventory
    {
        return Inventory::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
    }

    private function seedStock(float $qty = 50, float $avgCost = 80000): void
    {
        Inventory::factory()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $qty,
            'avg_cost' => $avgCost,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);
    }

    private function makeCustomer(): Company
    {
        return Company::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => CompanyType::Customer,
        ]);
    }

    // ── NHẬP KHO QUA ĐƠN MUA ────────────────────────────────────────────────

    #[TestDox('Xác nhận đơn nhập làm tăng tồn kho')]
    public function test_confirm_purchase_increases_stock(): void
    {
        $supplier = Company::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => CompanyType::Supplier,
        ]);

        $order = $this->postJson('/api/v1/purchases', [
            'company_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 20,
                'unit_price' => 80000,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/purchases/{$order['id']}/confirm")->assertOk();

        $inv = $this->getInventory();
        $this->assertNotNull($inv, 'Bản ghi tồn kho chưa được tạo');
        $this->assertEquals(20, (float) $inv->quantity, 'Tồn kho sau nhập phải là 20');
    }

    #[TestDox('Xác nhận đơn nhập cập nhật giá bình quân (avg_cost)')]
    public function test_confirm_purchase_updates_avg_cost(): void
    {
        $supplier = Company::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => CompanyType::Supplier,
        ]);

        $order = $this->postJson('/api/v1/purchases', [
            'company_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 10,
                'unit_price' => 100000,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/purchases/{$order['id']}/confirm")->assertOk();

        $inv = $this->getInventory();
        $this->assertEquals(100000, (float) $inv->avg_cost, 'avg_cost phải cập nhật theo giá nhập');
    }

    // ── XUẤT KHO QUA ĐƠN BÁN ────────────────────────────────────────────────

    #[TestDox('Xác nhận đơn bán làm giảm tồn kho')]
    public function test_confirm_sale_decreases_stock(): void
    {
        $this->seedStock();
        $customer = $this->makeCustomer();

        $order = $this->postJson('/api/v1/sales', [
            'company_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 10,
                'unit_price' => 120000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/sales/{$order['id']}/confirm")->assertOk();

        $this->assertEquals(40, (float) $this->getInventory()->quantity, 'Tồn kho sau bán phải giảm từ 50 xuống 40');
    }

    #[TestDox('Tạo đơn bán đặt hàng trước làm tăng reserved_quantity')]
    public function test_create_sale_increases_reserved_quantity(): void
    {
        $this->seedStock();
        $customer = $this->makeCustomer();

        $this->postJson('/api/v1/sales', [
            'company_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 15,
                'unit_price' => 120000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ])->assertCreated();

        $inv = $this->getInventory();
        $this->assertEquals(15, (float) $inv->reserved_quantity, 'reserved_quantity phải tăng lên 15 khi tạo đơn nháp');
        $this->assertEquals(35, (float) $inv->available_quantity, 'Có thể bán = 50 - 15 = 35');
    }

    // ── TRẢ HÀNG BÁN ────────────────────────────────────────────────────────

    #[TestDox('Trả hàng bán làm tăng lại tồn kho')]
    public function test_return_items_increases_stock(): void
    {
        $this->seedStock();
        $customer = $this->makeCustomer();

        $order = $this->postJson('/api/v1/sales', [
            'company_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 10,
                'unit_price' => 120000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/sales/{$order['id']}/confirm")->assertOk();
        $this->assertEquals(40, (float) $this->getInventory()->quantity);

        $this->postJson("/api/v1/sales/{$order['id']}/return-items", [
            'return_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 3,
            ]],
        ])->assertOk();

        $this->assertEquals(43, (float) $this->getInventory()->quantity, 'Tồn kho phải tăng lại sau khi khách trả hàng');
    }

    // ── ĐIỀU CHỈNH TỒN KHO ───────────────────────────────────────────────────

    #[TestDox('Điều chỉnh tồn kho tăng (nhập thêm)')]
    public function test_positive_adjustment_increases_stock(): void
    {
        $this->seedStock(qty: 30);

        $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Kiểm kê phát hiện thừa',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity_delta' => 5,
                'unit_price' => 80000,
            ]],
        ])->assertCreated();

        $this->assertEquals(35, (float) $this->getInventory()->quantity, 'Tồn kho phải tăng từ 30 lên 35');
    }

    #[TestDox('Điều chỉnh tồn kho giảm (xuất hủy)')]
    public function test_negative_adjustment_decreases_stock(): void
    {
        $this->seedStock(qty: 30);

        $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Kiểm kê phát hiện thiếu',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity_delta' => -8,
            ]],
        ])->assertCreated();

        $this->assertEquals(22, (float) $this->getInventory()->quantity, 'Tồn kho phải giảm từ 30 xuống 22');
    }

    #[TestDox('Điều chỉnh tồn kho cập nhật avg_cost khi nhập thêm')]
    public function test_positive_adjustment_recalculates_avg_cost(): void
    {
        // 10 cái @ 80,000 = 800,000
        $this->seedStock(qty: 10, avgCost: 80000);

        // Nhập thêm 10 cái @ 100,000 → avg = (800,000 + 1,000,000) / 20 = 90,000
        $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'transaction_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity_delta' => 10,
                'unit_price' => 100000,
            ]],
        ])->assertCreated();

        $inv = $this->getInventory();
        $this->assertEquals(20, (float) $inv->quantity);
        $this->assertEquals(90000, (float) $inv->avg_cost, 'avg_cost mới phải là bình quân gia quyền = 90,000');
    }

    #[TestDox('Hủy phiếu điều chỉnh hoàn lại tồn kho')]
    public function test_delete_adjustment_reverts_stock(): void
    {
        $this->seedStock(qty: 30);

        $adj = $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'transaction_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity_delta' => 10,
                'unit_price' => 80000,
            ]],
        ])->assertCreated()->json('id');

        $this->assertEquals(40, (float) $this->getInventory()->quantity);

        $this->deleteJson("/api/v1/inventory/adjustments/{$adj}")->assertOk();

        $this->assertEquals(30, (float) $this->getInventory()->quantity, 'Hủy phiếu phải hoàn lại tồn kho về 30');
    }

    #[TestDox('Điều chỉnh tồn kho yêu cầu quantity_delta khác 0')]
    public function test_adjustment_requires_non_zero_delta(): void
    {
        $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'transaction_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity_delta' => 0,
            ]],
        ])->assertUnprocessable();
    }

    // ── LỊCH SỬ XUẤT NHẬP ────────────────────────────────────────────────────

    #[TestDox('Lịch sử xuất nhập ghi nhận giao dịch điều chỉnh')]
    public function test_product_history_records_adjustment_transaction(): void
    {
        $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'transaction_date' => now()->toDateString(),
            'description' => 'Nhập hàng đầu kỳ',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity_delta' => 100,
                'unit_price' => 80000,
            ]],
        ])->assertCreated();

        $response = $this->getJson("/api/v1/inventory/product/{$this->product->id}/history")
            ->assertOk();

        $history = $response->json('data');
        $this->assertNotEmpty($history, 'Lịch sử phải có ít nhất 1 giao dịch');

        $tx = collect($history)->first();
        $this->assertEquals(100, (float) $tx['quantity'], 'Số lượng giao dịch phải là 100');
        $this->assertSame('adjustment', $tx['type']['value'] ?? $tx['type']);
    }
}
