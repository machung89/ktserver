<?php

namespace Tests\Feature;

use App\Jobs\SendPromotionPush;
use App\Models\Organization;
use App\Models\PushSubscription;
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
