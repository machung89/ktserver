<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
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
}
