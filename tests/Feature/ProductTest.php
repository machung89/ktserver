<?php

namespace Tests\Feature;

use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionItem;
use App\Models\Product;
use App\Models\SalesOrderItem;
use App\Models\Warehouse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ProductTest extends TestCase
{
    private function makeProduct(array $attrs = []): Product
    {
        return Product::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
        ], $attrs));
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'code' => 'SP-TEST-'.rand(1000, 9999),
            'name' => 'Sản phẩm test',
            'unit' => 'cái',
            'price' => 100000,
            'cost_price' => 70000,
        ], $override);
    }

    #[TestDox('Lấy danh sách sản phẩm')]
    public function test_can_list_products(): void
    {
        $this->makeProduct();
        $this->makeProduct();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'is_active']]]);
    }

    #[TestDox('Lọc chỉ hiển thị sản phẩm đang bán (active_only)')]
    public function test_active_only_filter_excludes_inactive_products(): void
    {
        $this->makeProduct(['is_active' => true]);
        $this->makeProduct(['is_active' => false]);

        $response = $this->getJson('/api/v1/products?active_only=1');

        $response->assertOk();
        $this->assertTrue(
            collect($response->json('data'))->every(fn ($p) => $p['is_active'] === true)
        );
    }

    #[TestDox('Tạo mới sản phẩm')]
    public function test_can_create_product(): void
    {
        $this->postJson('/api/v1/products', $this->payload(['code' => 'SP-001', 'name' => 'Test Product']))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Test Product');

        $this->assertDatabaseHas('products', [
            'code' => 'SP-001',
            'organization_id' => $this->organization->id,
        ]);
    }

    #[TestDox('Không thể tạo sản phẩm trùng mã')]
    public function test_cannot_create_duplicate_code(): void
    {
        $this->makeProduct(['code' => 'SP-DUP']);

        $this->postJson('/api/v1/products', $this->payload(['code' => 'SP-DUP']))
            ->assertUnprocessable();
    }

    #[TestDox('Tạo sản phẩm yêu cầu các trường bắt buộc')]
    public function test_create_product_requires_required_fields(): void
    {
        $this->postJson('/api/v1/products', [])->assertUnprocessable();
        $this->postJson('/api/v1/products', ['name' => 'No code'])->assertUnprocessable();
    }

    #[TestDox('Cập nhật thông tin sản phẩm')]
    public function test_can_update_product(): void
    {
        $product = $this->makeProduct();

        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Tên mới'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Tên mới');
    }

    #[TestDox('Ngừng bán sản phẩm (is_active = false)')]
    public function test_can_deactivate_product(): void
    {
        $product = $this->makeProduct(['is_active' => true]);

        $this->putJson("/api/v1/products/{$product->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => 0]);
    }

    #[TestDox('Mở bán lại sản phẩm đã ngừng')]
    public function test_can_reactivate_product(): void
    {
        $product = $this->makeProduct(['is_active' => false]);

        $this->putJson("/api/v1/products/{$product->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    #[TestDox('Sản phẩm ngừng bán bị loại khỏi danh sách active_only')]
    public function test_inactive_product_excluded_from_active_only_list(): void
    {
        $product = $this->makeProduct(['is_active' => true]);

        $this->putJson("/api/v1/products/{$product->id}", ['is_active' => false]);

        $response = $this->getJson('/api/v1/products?active_only=1');
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($product->id, $ids);
    }

    #[TestDox('Xóa sản phẩm không có liên kết')]
    public function test_can_delete_product_with_no_links(): void
    {
        $product = $this->makeProduct();

        $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    #[TestDox('Không thể xóa sản phẩm đang có giao dịch kho')]
    public function test_cannot_delete_product_with_inventory_transactions(): void
    {
        $product = $this->makeProduct();
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);

        $tx = InventoryTransaction::factory()->create([
            'organization_id' => $this->organization->id,
            'warehouse_id' => $warehouse->id,
        ]);
        InventoryTransactionItem::factory()->create([
            'inventory_transaction_id' => $tx->id,
            'product_id' => $product->id,
        ]);

        $response = $this->deleteJson("/api/v1/products/{$product->id}");

        $response->assertUnprocessable();
        $this->assertStringContainsString('giao dịch kho', $response->json('message'));
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    #[TestDox('Không thể xóa sản phẩm đang có trong đơn bán')]
    public function test_cannot_delete_product_linked_to_sales_order_item(): void
    {
        $product = $this->makeProduct();

        SalesOrderItem::factory()->create(['product_id' => $product->id]);

        $response = $this->deleteJson("/api/v1/products/{$product->id}");

        $response->assertUnprocessable();
        $this->assertStringContainsString('đơn bán', $response->json('message'));
    }
}
