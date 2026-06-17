<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\DefaultAccountsService;
use App\Services\SalesOrderService;
use Database\Seeders\SystemAccountsSeeder;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    private Company $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Company::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => CompanyType::Customer,
        ]);

        $this->warehouse = Warehouse::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'price' => 100000,
            'cost_price' => 60000,
            'is_active' => true,
        ]);

        Inventory::factory()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        // Hệ thống tài khoản kế toán chuẩn (cần cho bút toán khi xác nhận đơn)
        $this->seed(SystemAccountsSeeder::class);
        app(DefaultAccountsService::class)->seedForOrganization($this->organization->id);
    }

    private function validPayload(array $override = []): array
    {
        return array_merge([
            'company_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 2,
                'unit_price' => 100000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ], $override);
    }

    #[TestDox('Tạo đơn bán hàng')]
    public function test_can_create_sales_order(): void
    {
        $response = $this->postJson('/api/v1/sales', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->assertEquals(200000, (float) $response->json('data.total_amount'));

        $this->assertDatabaseHas('sales_orders', [
            'organization_id' => $this->organization->id,
            'company_id' => $this->customer->id,
        ]);
    }

    #[TestDox('Tạo đơn bán yêu cầu có sản phẩm')]
    public function test_create_requires_items(): void
    {
        $this->postJson('/api/v1/sales', ['order_date' => now()->toDateString()])
            ->assertUnprocessable();
    }

    #[TestDox('Đơn trả hàng liên kết với đơn gốc qua original_order_id')]
    public function test_return_order_links_to_original(): void
    {
        $original = $this->postJson('/api/v1/sales', $this->validPayload())
            ->assertCreated()
            ->json('data');

        $return = $this->postJson('/api/v1/sales', $this->validPayload([
            'is_return_order' => true,
            'original_order_id' => $original['id'],
            'notes' => "Đơn {$original['order_number']} trả hàng",
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => -2,
                'unit_price' => 100000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ]))->assertCreated()->json('data');

        $this->assertDatabaseHas('sales_orders', [
            'id' => $return['id'],
            'original_order_id' => $original['id'],
        ]);
    }

    #[TestDox('Đơn gốc hiển thị has_return = true khi đã có đơn trả')]
    public function test_original_order_shows_has_return_true(): void
    {
        $original = $this->postJson('/api/v1/sales', $this->validPayload())
            ->assertCreated()->json('data');

        $this->postJson('/api/v1/sales', $this->validPayload([
            'is_return_order' => true,
            'original_order_id' => $original['id'],
            'notes' => 'Trả hàng',
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => -1,
                'unit_price' => 100000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ]))->assertCreated();

        $this->getJson("/api/v1/sales/{$original['id']}")
            ->assertOk()
            ->assertJsonPath('data.has_return', true);
    }

    #[TestDox('Đơn chưa có trả hàng hiển thị has_return = false')]
    public function test_order_without_return_shows_has_return_false(): void
    {
        $order = $this->postJson('/api/v1/sales', $this->validPayload())
            ->assertCreated()->json('data');

        $this->getJson("/api/v1/sales/{$order['id']}")
            ->assertOk()
            ->assertJsonPath('data.has_return', false);
    }

    #[TestDox('Sản phẩm ngừng bán không xuất hiện trong danh sách active_only')]
    public function test_inactive_product_not_in_active_only_list(): void
    {
        $inactive = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/products?active_only=1');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($inactive->id, $ids);
    }

    #[TestDox('Lấy danh sách đơn bán hàng')]
    public function test_can_list_sales_orders(): void
    {
        $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated();

        $this->getJson('/api/v1/sales')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'order_number', 'status', 'total_amount']]]);
    }

    #[TestDox('Bán combo: giá vốn = tổng giá vốn thành phần, trừ kho thành phần (không trừ combo)')]
    public function test_combo_sale_cost_and_inventory(): void
    {
        // 2 sản phẩm thành phần có tồn + giá vốn TB
        $compA = Product::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true]);
        $compB = Product::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true]);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $compA->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'avg_cost' => 10000, 'reserved_quantity' => 0, 'min_quantity' => 0]);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $compB->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'avg_cost' => 5000, 'reserved_quantity' => 0, 'min_quantity' => 0]);

        // SP combo: 2 x compA + 1 x compB
        $combo = Product::factory()->create(['organization_id' => $this->organization->id, 'price' => 50000, 'product_type' => 'product', 'is_active' => true]);
        $recipe = Recipe::create(['organization_id' => $this->organization->id, 'product_id' => $combo->id, 'type' => 'combo', 'yield_quantity' => 1]);
        $recipe->ingredients()->createMany([
            ['ingredient_id' => $compA->id, 'quantity' => 2],
            ['ingredient_id' => $compB->id, 'quantity' => 1],
        ]);

        // Tạo đơn bán 3 combo rồi xác nhận
        $order = $this->postJson('/api/v1/sales', $this->validPayload([
            'items' => [[
                'product_id' => $combo->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 3,
                'unit_price' => 50000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ]))->assertCreated()->json('data');

        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));

        // Giá vốn combo/đơn vị = 2*10000 + 1*5000 = 25000
        $this->assertDatabaseHas('sales_order_items', [
            'sales_order_id' => $order['id'],
            'product_id' => $combo->id,
            'cost_price' => 25000,
        ]);

        // Trừ kho thành phần: A 100-6=94 ; B 100-3=97
        $this->assertEquals(94.0, (float) Inventory::where('product_id', $compA->id)->value('quantity'));
        $this->assertEquals(97.0, (float) Inventory::where('product_id', $compB->id)->value('quantity'));

        // Combo không có tồn riêng (không bị trừ)
        $comboQty = Inventory::where('product_id', $combo->id)->value('quantity');
        $this->assertTrue($comboQty === null || (float) $comboQty === 0.0);

        // Bút toán: 632/156 = giá vốn thành phần (3*25000=75000); 511/131 = doanh thu (3*50000=150000)
        $ledger = fn (string $code) => \DB::table('journal_entry_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', SalesOrder::class)
            ->where('j.reference_id', $order['id'])
            ->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')
            ->first();

        $this->assertEquals(75000.0, (float) $ledger('632')->d, 'Nợ 632 = giá vốn thành phần');
        $this->assertEquals(75000.0, (float) $ledger('156')->c, 'Có 156 = giảm tồn kho thành phần');
        $this->assertEquals(150000.0, (float) $ledger('511')->c, 'Có 511 = doanh thu combo');
        $this->assertEquals(150000.0, (float) $ledger('131')->d, 'Nợ 131 = phải thu');
    }

    #[TestDox('Chiết khấu cấp đơn: lưu CK, xác nhận được và bút toán cân (511 = doanh thu thuần)')]
    public function test_order_discount_persisted_and_journal_balanced(): void
    {
        // 2 x 100.000 = 200.000, chiết khấu cấp đơn cố định 20.000 → tổng 180.000
        $order = $this->postJson('/api/v1/sales', $this->validPayload([
            'discount_type' => 'fixed',
            'discount_value' => 20000,
        ]))->assertCreated()->json('data');

        // CK được lưu thật vào DB
        $this->assertDatabaseHas('sales_orders', [
            'id' => $order['id'],
            'discount_type' => 'fixed',
            'discount_amount' => 20000,
            'subtotal' => 200000,
            'total_amount' => 180000,
        ]);

        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));

        // Bút toán cân + 511 = doanh thu thuần 180.000; 131 = 180.000
        $line = fn (string $code) => \DB::table('journal_entry_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', SalesOrder::class)->where('j.reference_id', $order['id'])
            ->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')->first();

        $this->assertEquals(180000.0, (float) $line('511')->c, '511 = doanh thu thuần (đã trừ CK)');
        $this->assertEquals(180000.0, (float) $line('131')->d, '131 = tổng phải thu');

        $sums = \DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', SalesOrder::class)->where('j.reference_id', $order['id'])
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')->first();
        $this->assertEquals((float) $sums->d, (float) $sums->c, 'Tổng Nợ = Tổng Có');
    }

    #[TestDox('Báo cáo doanh thu theo SP khớp với tổng đơn (phân bổ chiết khấu cấp đơn)')]
    public function test_product_revenue_report_reconciles_with_order_total(): void
    {
        // Đơn 1: 200.000 không CK
        $o1 = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        // Đơn 2: CK cấp đơn 20.000 → tổng 180.000
        $o2 = $this->postJson('/api/v1/sales', $this->validPayload([
            'discount_type' => 'fixed', 'discount_value' => 20000,
        ]))->assertCreated()->json('data');

        app(SalesOrderService::class)->confirm(SalesOrder::find($o1['id']));
        app(SalesOrderService::class)->confirm(SalesOrder::find($o2['id']));

        // Doanh thu báo cáo theo SP = tổng total_amount = 200.000 + 180.000 = 380.000 (không phải 400.000 gộp)
        $res = $this->getJson('/api/v1/reports/sales?group_by=product')->assertOk()->json();
        $this->assertEquals(380000.0, (float) $res['total_revenue']);
    }

    #[TestDox('Xác nhận hàng loạt theo mã vận đơn: nháp được xác nhận, trạng thái khác bỏ qua, mã sai báo không tìm thấy')]
    public function test_bulk_confirm_by_code(): void
    {
        // Đơn nháp + có mã vận đơn → sẽ được xác nhận
        $draft = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        \DB::table('sales_orders')->where('id', $draft['id'])->update(['tracking_number' => 'VD-001']);

        // Đơn đã ở trạng thái khác (completed) → bỏ qua
        $other = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        \DB::table('sales_orders')->where('id', $other['id'])->update(['status' => 'completed', 'tracking_number' => 'VD-002']);

        $response = $this->postJson('/api/v1/sales/bulk-confirm-by-code', [
            'codes' => ['VD-001', 'VD-002', 'KHONG-TON-TAI'],
        ])->assertOk();

        $response->assertJsonPath('summary.confirmed', 1)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('summary.not_found', 1);

        $this->assertDatabaseHas('sales_orders', [
            'id' => $draft['id'],
            'status' => 'confirmed',
        ]);
    }
}
