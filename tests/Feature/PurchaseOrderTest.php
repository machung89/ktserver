<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
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
