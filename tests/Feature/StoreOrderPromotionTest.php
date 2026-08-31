<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Warehouse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class StoreOrderPromotionTest extends TestCase
{
    #[TestDox('Quote: giảm giá đơn hàng (order_discount) áp khi đủ giá trị tối thiểu')]
    public function test_quote_applies_order_discount(): void
    {
        $this->organization->update(['public_token' => 'SHOP-Q']);
        $product = Product::factory()->create(['organization_id' => $this->organization->id, 'price' => 300000, 'is_active' => true, 'product_type' => 'product']);
        Promotion::create([
            'organization_id' => $this->organization->id,
            'name' => 'Giảm 10% đơn từ 500k',
            'type' => 'order_discount',
            'scope' => 'all',
            'conditions' => ['min_order_value' => 500000, 'discount_type' => 'percent', 'discount_value' => 10],
            'is_active' => true,
        ]);

        $this->postJson('/api/public/store/SHOP-Q/quote', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('subtotal', 600000)
            ->assertJsonPath('order_discount_amount', 60000)
            ->assertJsonPath('total', 540000);
    }

    #[TestDox('Đặt hàng: order_discount ghi vào đơn (promotion_id + discount_amount + total)')]
    public function test_place_order_persists_order_discount(): void
    {
        $this->organization->update(['public_token' => 'SHOP-Q2']);
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);
        $product = Product::factory()->create(['organization_id' => $this->organization->id, 'price' => 300000, 'is_active' => true, 'product_type' => 'product']);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100]);
        $promo = Promotion::create([
            'organization_id' => $this->organization->id,
            'name' => 'Giảm 10%',
            'type' => 'order_discount',
            'scope' => 'all',
            'conditions' => ['min_order_value' => 500000, 'discount_type' => 'percent', 'discount_value' => 10],
            'is_active' => true,
        ]);

        $this->postJson('/api/public/store/SHOP-Q2/order', [
            'customer_name' => 'A', 'customer_phone' => '0900000000',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertDatabaseHas('sales_orders', [
            'organization_id' => $this->organization->id,
            'promotion_id' => $promo->id,
            'discount_amount' => 60000,
            'total_amount' => 540000,
        ]);
    }

    #[TestDox('Đặt hàng: mua X tặng Y tự thêm dòng quà (giá 0) và giữ chỗ tồn quà')]
    public function test_place_order_adds_gift_line(): void
    {
        $this->organization->update(['public_token' => 'SHOP-G']);
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);
        $buy = Product::factory()->create(['organization_id' => $this->organization->id, 'price' => 10000, 'is_active' => true, 'product_type' => 'product']);
        $gift = Product::factory()->create(['organization_id' => $this->organization->id, 'price' => 5000, 'is_active' => true, 'product_type' => 'product']);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $buy->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100]);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $gift->id, 'warehouse_id' => $warehouse->id, 'quantity' => 100]);
        Promotion::create([
            'organization_id' => $this->organization->id,
            'name' => 'Mua 2 tặng 1',
            'type' => 'buy_x_get_y',
            'scope' => 'product',
            'conditions' => ['rules' => [['buy_product_ids' => [$buy->id], 'min_qty' => 2, 'gift_product_id' => $gift->id, 'gift_qty' => 1]]],
            'is_active' => true,
        ]);

        $this->postJson('/api/public/store/SHOP-G/order', [
            'customer_name' => 'A', 'customer_phone' => '0900000000',
            'items' => [['product_id' => $buy->id, 'quantity' => 2]],
        ])->assertCreated();

        // Dòng quà giá 0
        $this->assertDatabaseHas('sales_order_items', [
            'product_id' => $gift->id, 'quantity' => 1, 'unit_price' => 0, 'amount' => 0,
        ]);
        // Giữ chỗ tồn quà
        $this->assertEquals(1, Inventory::where('product_id', $gift->id)->where('warehouse_id', $warehouse->id)->value('reserved_quantity'));
    }
}
