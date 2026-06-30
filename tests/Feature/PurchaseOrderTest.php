<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\DefaultAccountsService;
use App\Services\PurchaseOrderService;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    private Company $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => CompanyType::Supplier,
        ]);

        $this->warehouse = Warehouse::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'cost_price' => 50000,
            'is_active' => true,
        ]);
    }

    private function validPayload(array $override = []): array
    {
        return array_merge([
            'company_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 10,
                'unit_price' => 60000,
            ]],
        ], $override);
    }

    #[TestDox('Thành tiền/thuế/tổng đơn nhập được làm tròn về đồng nguyên khi lưu')]
    public function test_amounts_rounded_to_integer_on_save(): void
    {
        // 3 × 33.333,33 = 99.999,99 → làm tròn 100.000; VAT 10% = 10.000; tổng 110.000
        $data = $this->postJson('/api/v1/purchases', $this->validPayload([
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 3,
                'unit_price' => 33333.33,
                'tax_rate' => 10,
            ]],
        ]))->assertCreated()->json('data');

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $data['id'],
            'amount' => 100000,
        ]);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $data['id'],
            'subtotal' => 100000,
            'tax_amount' => 10000,
            'total_amount' => 110000,
        ]);

        // Sửa đơn cũng phải lưu giá trị đã làm tròn
        $this->putJson("/api/v1/purchases/{$data['id']}", $this->validPayload([
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 3,
                'unit_price' => 33333.33,
                'tax_rate' => 0,
            ]],
        ]))->assertOk();

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $data['id'],
            'subtotal' => 100000,
            'tax_amount' => 0,
            'total_amount' => 100000,
        ]);
    }

    #[TestDox('Cho phép số lượng âm (trả hàng NCC): tạo được + xác nhận giảm tồn, bút toán cân')]
    public function test_allows_negative_quantity_and_confirm_balances(): void
    {
        app()->instance('orgId', $this->organization->id);
        $this->seed(SystemAccountsSeeder::class);
        app(DefaultAccountsService::class)->seedForOrganization($this->organization->id);

        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'avg_cost' => 50000, 'stock_value' => 5000000, 'reserved_quantity' => 0, 'min_quantity' => 0]);

        // Đơn nhập SL âm 10 (trả hàng NCC) → thành tiền -600.000
        $order = $this->postJson('/api/v1/purchases', $this->validPayload([
            'items' => [['product_id' => $this->product->id, 'quantity' => -10, 'unit_price' => 60000]],
        ]))->assertCreated()->json('data');

        $this->assertDatabaseHas('purchase_orders', ['id' => $order['id'], 'total_amount' => -600000]);

        app(PurchaseOrderService::class)->confirm(PurchaseOrder::find($order['id']));

        // Tồn giảm 100 → 90
        $this->assertEquals(90.0, (float) Inventory::where('product_id', $this->product->id)->value('quantity'));

        // Bút toán cân
        $sums = DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', PurchaseOrder::class)->where('j.reference_id', $order['id'])
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')->first();
        $this->assertEquals((float) $sums->d, (float) $sums->c, 'Bút toán đơn nhập âm vẫn cân');
    }

    #[TestDox('Đề xuất nhập: loại combo/định mức, cộng tiêu hao nguyên liệu từ combo bán ra')]
    public function test_suggest_excludes_combo_and_counts_ingredient_consumption(): void
    {
        $compA = Product::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true, 'cost_price' => 10000]);
        $combo = Product::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true]);
        Recipe::create(['organization_id' => $this->organization->id, 'product_id' => $combo->id, 'type' => 'combo', 'yield_quantity' => 1])
            ->ingredients()->create(['ingredient_id' => $compA->id, 'quantity' => 2]);

        // Nguyên liệu compA tồn 0 → sẽ thiếu khi combo bán
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $compA->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'reserved_quantity' => 0, 'avg_cost' => 10000, 'stock_value' => 0, 'min_quantity' => 0]);

        $customer = Company::factory()->create(['organization_id' => $this->organization->id, 'type' => CompanyType::Customer]);
        $so = SalesOrder::create([
            'organization_id' => $this->organization->id, 'company_id' => $customer->id,
            'order_number' => 'SODB1', 'order_date' => now()->toDateString(), 'status' => 'completed',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'created_by' => $this->user->id,
        ]);
        // Bán 30 combo trong kỳ → tiêu hao compA = 30 × 2 = 60 → tốc độ 2/ngày
        $so->items()->create(['product_id' => $combo->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 30, 'unit_price' => 50000, 'amount' => 1500000, 'cost_price' => 20000, 'standard_price' => 0, 'tax_rate' => 0, 'is_return' => false]);

        $data = $this->getJson('/api/v1/purchases/suggest?days=15')->assertOk()->json('data');
        $byId = collect($data)->keyBy('product_id');

        // Combo KHÔNG được đề xuất nhập; nguyên liệu compA ĐƯỢC đề xuất theo tiêu hao qua combo
        $this->assertArrayNotHasKey($combo->id, $byId->all(), 'Combo không được đề xuất nhập');
        $this->assertArrayHasKey($compA->id, $byId->all(), 'Nguyên liệu phải được đề xuất theo tiêu hao combo');
        $this->assertEquals(2.0, (float) $byId[$compA->id]['velocity']);   // 60/30
        $this->assertEquals(30.0, (float) $byId[$compA->id]['suggest_qty']); // 2 × 15 − 0
    }

    #[TestDox('Tạo đơn nhập hàng')]
    public function test_can_create_purchase_order(): void
    {
        $response = $this->postJson('/api/v1/purchases', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->assertEquals(600000, (float) $response->json('data.total_amount'));

        $this->assertDatabaseHas('purchase_orders', [
            'organization_id' => $this->organization->id,
            'company_id' => $this->supplier->id,
        ]);
    }

    #[TestDox('Tạo đơn nhập yêu cầu nhà cung cấp, kho và sản phẩm')]
    public function test_create_requires_supplier_warehouse_and_items(): void
    {
        $this->postJson('/api/v1/purchases', [])->assertUnprocessable();

        $this->postJson('/api/v1/purchases', [
            'company_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
        ])->assertUnprocessable();
    }

    #[TestDox('Ghi nhớ giá nhập vào sản phẩm khi tích tùy chọn')]
    public function test_create_with_update_cost_price_updates_product(): void
    {
        $this->postJson('/api/v1/purchases', $this->validPayload([
            'update_cost_price' => true,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 5,
                'unit_price' => 80000,
            ]],
        ]))->assertCreated();

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'cost_price' => 80000,
        ]);
    }

    #[TestDox('Không ghi nhớ giá nhập khi không tích tùy chọn')]
    public function test_create_without_update_cost_price_keeps_original_price(): void
    {
        $originalCost = $this->product->cost_price;

        $this->postJson('/api/v1/purchases', $this->validPayload([
            'update_cost_price' => false,
        ]))->assertCreated();

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'cost_price' => $originalCost,
        ]);
    }

    #[TestDox('Tính đúng tổng tiền đơn nhập')]
    public function test_purchase_order_calculates_totals_correctly(): void
    {
        $response = $this->postJson('/api/v1/purchases', $this->validPayload([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 100000],
            ],
        ]))->assertCreated();

        $this->assertEquals(200000, $response->json('data.total_amount'));
        $this->assertEquals(200000, $response->json('data.subtotal'));
        $this->assertEquals(0, $response->json('data.tax_amount'));
    }

    #[TestDox('Lấy danh sách đơn nhập hàng')]
    public function test_can_list_purchase_orders(): void
    {
        $this->postJson('/api/v1/purchases', $this->validPayload())->assertCreated();

        $this->getJson('/api/v1/purchases')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'order_number', 'status', 'total_amount']]]);
    }

    #[TestDox('Báo cáo nhập hàng chỉ tính đơn đã xác nhận/hoàn thành')]
    public function test_purchase_report_counts_confirmed_orders(): void
    {
        // Đơn nháp — không được tính
        $this->postJson('/api/v1/purchases', $this->validPayload())->assertCreated();

        // Đơn đã xác nhận — được tính (10 x 60.000 = 600.000)
        $confirmed = $this->postJson('/api/v1/purchases', $this->validPayload())->json('data.id');
        PurchaseOrder::withoutGlobalScopes()->where('id', $confirmed)->update(['status' => 'confirmed']);

        $res = $this->getJson('/api/v1/reports/purchases?group_by=product')->assertOk()->json();
        $this->assertEquals(600000, (float) $res['total_amount']);
        $this->assertEquals(10, (float) $res['total_quantity']);
        $this->assertCount(1, $res['data']);

        // Lọc theo nhà cung cấp khác → không có dữ liệu
        $other = Company::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => CompanyType::Supplier,
        ]);
        $empty = $this->getJson("/api/v1/reports/purchases?supplier_id={$other->id}")->assertOk()->json();
        $this->assertEquals(0, (float) $empty['total_amount']);

        // Nhóm theo nhà cung cấp
        $bySupplier = $this->getJson('/api/v1/reports/purchases?group_by=supplier')->assertOk()->json();
        $this->assertEquals($this->supplier->id, $bySupplier['data'][0]['company_id']);
    }
}
