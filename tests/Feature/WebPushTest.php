<?php

namespace Tests\Feature;

use App\Jobs\SendPromotionPush;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PushSubscription;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    #[TestDox('Đăng ký Web Push công khai (không đăng nhập) theo token cửa hàng; không tạo trùng endpoint')]
    public function test_public_subscribe_saves_subscription(): void
    {
        $this->organization->update(['public_token' => 'STORE-TOKEN-1']);

        $this->postJson('/api/public/store/STORE-TOKEN-1/push-subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'keys' => ['p256dh' => 'PUBKEY', 'auth' => 'AUTHTOKEN'],
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('push_subscriptions', [
            'organization_id' => $this->organization->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'public_key' => 'PUBKEY',
            'auth_token' => 'AUTHTOKEN',
        ]);

        // Cùng endpoint đăng ký lại → cập nhật, không nhân bản
        $this->postJson('/api/public/store/STORE-TOKEN-1/push-subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'keys' => ['p256dh' => 'PUBKEY2', 'auth' => 'AUTH2'],
        ])->assertOk();

        $this->assertEquals(1, PushSubscription::where('organization_id', $this->organization->id)->count());
    }

    #[TestDox('Token sai không đăng ký được (404)')]
    public function test_subscribe_wrong_token_404(): void
    {
        $this->organization->update(['public_token' => 'GOOD']);
        $this->postJson('/api/public/store/WRONG/push-subscribe', [
            'endpoint' => 'x', 'keys' => ['p256dh' => 'a', 'auth' => 'b'],
        ])->assertNotFound();
    }

    #[TestDox('Config công khai trả về VAPID public key + tên shop')]
    public function test_public_config_returns_vapid_key(): void
    {
        $this->organization->update(['public_token' => 'STK2']);

        $this->getJson('/api/public/store/STK2/config')
            ->assertOk()
            ->assertJsonPath('shop_name', $this->organization->name)
            ->assertJsonStructure(['vapid_public_key']);
    }

    #[TestDox('Tạo KM mới (active) đẩy job push; notify=false thì không đẩy')]
    public function test_promotion_create_dispatches_push(): void
    {
        Bus::fake();

        $conditions = ['rules' => [['min_qty' => 1, 'discount_value' => 10]]];

        $this->postJson('/api/v1/promotions', [
            'name' => 'KM 1', 'type' => 'order_discount', 'conditions' => $conditions, 'is_active' => true,
        ])->assertCreated();

        $this->postJson('/api/v1/promotions', [
            'name' => 'KM 2', 'type' => 'order_discount', 'conditions' => $conditions, 'is_active' => true, 'notify' => false,
        ])->assertCreated();

        Bus::assertDispatchedTimes(SendPromotionPush::class, 1);
    }

    #[TestDox('Cách ly org: subscription của org này không lọt sang org khác')]
    public function test_subscriptions_are_isolated_per_organization(): void
    {
        $this->organization->update(['public_token' => 'ORG-A']);
        $orgB = Organization::create(['name' => 'Shop B', 'is_active' => true, 'public_token' => 'ORG-B']);

        $this->postJson('/api/public/store/ORG-A/push-subscribe', [
            'endpoint' => 'https://push/dev-a', 'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertOk();

        $this->postJson('/api/public/store/ORG-B/push-subscribe', [
            'endpoint' => 'https://push/dev-b', 'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertOk();

        $this->assertEquals(1, $this->organization->pushSubscriptions()->count());
        $this->assertEquals('https://push/dev-a', $this->organization->pushSubscriptions()->first()->endpoint);
        $this->assertEquals(1, $orgB->pushSubscriptions()->count());
        $this->assertEquals('https://push/dev-b', $orgB->pushSubscriptions()->first()->endpoint);
    }

    #[TestDox('Khách xem danh sách sản phẩm công khai theo token')]
    public function test_public_products_list(): void
    {
        $this->organization->update(['public_token' => 'SHOP-P']);
        Product::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Cà phê', 'price' => 30000, 'is_active' => true, 'product_type' => 'product']);
        Product::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bánh', 'price' => 20000, 'is_active' => true, 'product_type' => 'product']);
        // Ẩn: inactive + nguyên liệu → không hiện
        Product::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Ẩn', 'price' => 1000, 'is_active' => false, 'product_type' => 'product']);
        Product::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Nguyên liệu', 'price' => 500, 'is_active' => true, 'product_type' => 'ingredient']);

        $this->getJson('/api/public/store/SHOP-P/products')
            ->assertOk()
            ->assertJsonCount(2, 'products');
    }

    #[TestDox('Promotions công khai trả về chi tiết điều kiện (details)')]
    public function test_public_promotions_include_details(): void
    {
        $this->organization->update(['public_token' => 'SHOP-PR']);
        Promotion::create([
            'organization_id' => $this->organization->id,
            'name' => 'Giảm đơn lớn',
            'type' => 'order_discount',
            'scope' => 'all',
            'conditions' => ['min_order_value' => 500000, 'discount_type' => 'percent', 'discount_value' => 10],
            'is_active' => true,
        ]);

        $this->getJson('/api/public/store/SHOP-PR/promotions')
            ->assertOk()
            ->assertJsonCount(1, 'promotions')
            ->assertJsonPath('promotions.0.details.0', 'Đơn từ 500.000₫ → giảm 10%');
    }

    #[TestDox('Khách tự đặt hàng → tạo đơn nháp + giữ chỗ tồn')]
    public function test_public_place_order_creates_draft_and_reserves(): void
    {
        $this->organization->update(['public_token' => 'SHOP-O']);
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);
        $product = Product::factory()->create(['organization_id' => $this->organization->id, 'price' => 25000, 'is_active' => true, 'product_type' => 'product']);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10, 'reserved_quantity' => 0]);

        $this->postJson('/api/public/store/SHOP-O/order', [
            'customer_name' => 'Khách A',
            'customer_phone' => '0900000000',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('sales_orders', [
            'organization_id' => $this->organization->id,
            'status' => 'draft',
            'total_amount' => 75000,
        ]);
        $this->assertEquals(3, Inventory::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('reserved_quantity'));
    }

    #[TestDox('SĐT trùng → gắn vào mã khách cũ, không tạo khách mới')]
    public function test_place_order_reuses_existing_customer_by_phone(): void
    {
        $this->organization->update(['public_token' => 'SHOP-C1']);
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);
        $product = Product::factory()->create(['organization_id' => $this->organization->id, 'price' => 10000, 'is_active' => true, 'product_type' => 'product']);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 50]);

        $existing = Company::create([
            'organization_id' => $this->organization->id,
            'name' => 'Khách Cũ', 'phone' => '0911222333', 'type' => 'customer', 'code' => 'KH0001', 'is_active' => true,
        ]);

        $this->postJson('/api/public/store/SHOP-C1/order', [
            'customer_name' => 'Tên Khác', 'customer_phone' => '0911222333',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertEquals(1, Company::where('organization_id', $this->organization->id)->where('phone', '0911222333')->count());
        $this->assertDatabaseHas('sales_orders', ['organization_id' => $this->organization->id, 'company_id' => $existing->id]);
    }

    #[TestDox('SĐT chưa tồn tại → tạo khách mới và gắn vào đơn')]
    public function test_place_order_creates_new_customer_when_phone_new(): void
    {
        $this->organization->update(['public_token' => 'SHOP-C2']);
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);
        $product = Product::factory()->create(['organization_id' => $this->organization->id, 'price' => 10000, 'is_active' => true, 'product_type' => 'product']);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 50]);

        $this->postJson('/api/public/store/SHOP-C2/order', [
            'customer_name' => 'Khách Mới', 'customer_phone' => '0988777666', 'address' => 'Số 1 ABC',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertDatabaseHas('companies', [
            'organization_id' => $this->organization->id, 'phone' => '0988777666', 'name' => 'Khách Mới', 'type' => 'customer',
        ]);
        $company = Company::where('organization_id', $this->organization->id)->where('phone', '0988777666')->first();
        $this->assertDatabaseHas('sales_orders', ['organization_id' => $this->organization->id, 'company_id' => $company->id]);
    }

    #[TestDox('Đặt hàng token sai → 404')]
    public function test_public_place_order_wrong_token(): void
    {
        $this->organization->update(['public_token' => 'GOODSHOP']);
        $this->postJson('/api/public/store/BAD/order', [
            'customer_name' => 'X', 'customer_phone' => '0900000000', 'items' => [['product_id' => 1, 'quantity' => 1]],
        ])->assertNotFound();
    }

    #[TestDox('Cùng 1 thiết bị theo dõi 2 shop: tách riêng theo org, không đè nhau')]
    public function test_same_device_two_shops_kept_separate(): void
    {
        $this->organization->update(['public_token' => 'A2']);
        $orgB = Organization::create(['name' => 'Shop B2', 'is_active' => true, 'public_token' => 'B2']);
        $endpoint = 'https://push/same-device';

        $this->postJson('/api/public/store/A2/push-subscribe', [
            'endpoint' => $endpoint, 'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertOk();
        $this->postJson('/api/public/store/B2/push-subscribe', [
            'endpoint' => $endpoint, 'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertOk();

        $this->assertEquals(1, $this->organization->pushSubscriptions()->count());
        $this->assertEquals(1, $orgB->pushSubscriptions()->count());
    }
}
