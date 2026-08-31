<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Warehouse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class MarketplaceImportTest extends TestCase
{
    #[TestDox('Import Shopee: không áp chiết khấu — amount = SL × đơn giá, discount = 0')]
    public function test_shopee_import_ignores_discount(): void
    {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);
        $product = Product::factory()->create(['organization_id' => $this->organization->id, 'code' => 'SKU1', 'price' => 100000, 'is_active' => true]);

        $this->postJson('/api/v1/sales/shopee-import', [
            'warehouse_id' => $warehouse->id,
            'rows' => [[
                'order_id' => 'SP1', 'status' => '', 'tracking_number' => 'TRACK1',
                'sku' => 'SKU1', 'quantity' => 2, 'unit_price' => 100000, 'discount_pct' => 20,
            ]],
        ])->assertOk();

        // amount phải là 200.000 (gộp), KHÔNG bị trừ 20% còn 160.000
        $this->assertDatabaseHas('sales_order_items', [
            'product_id' => $product->id,
            'quantity' => 2,
            'discount_type' => null,
            'discount_value' => 0,
            'amount' => 200000,
        ]);
    }

    #[TestDox('Import TikTok: không áp chiết khấu — amount = SL × đơn giá, discount = 0')]
    public function test_tiktok_import_ignores_discount(): void
    {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);
        $product = Product::factory()->create(['organization_id' => $this->organization->id, 'code' => 'TT1', 'price' => 50000, 'is_active' => true]);

        $this->postJson('/api/v1/sales/tiktok-import', [
            'warehouse_id' => $warehouse->id,
            'rows' => [[
                'order_id' => 'TK1', 'status' => '', 'tracking_number' => 'TRK',
                'sku' => 'TT1', 'quantity' => 3, 'unit_price' => 50000, 'discount_pct' => 30,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('sales_order_items', [
            'product_id' => $product->id,
            'quantity' => 3,
            'discount_type' => null,
            'discount_value' => 0,
            'amount' => 150000,
        ]);
    }
}
