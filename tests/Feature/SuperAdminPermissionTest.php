<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SuperAdminPermissionTest extends TestCase
{
    #[TestDox('Super admin toàn quyền dù không có role trong org đang truy cập (sau khi switch org)')]
    public function test_super_admin_has_all_permissions_without_roles(): void
    {
        // Org khác + super admin KHÔNG gắn role (mô phỏng đã switch sang org này)
        $orgB = Organization::create(['name' => 'Org B', 'is_active' => true]);
        $superAdmin = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
            'is_super_admin' => true,
        ]);
        $this->actingAs($superAdmin, 'sanctum');

        // /me: frontend nhận is_admin=true + permissions=['*']
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('is_admin', true)
            ->assertJsonPath('is_super_admin', true)
            ->assertJsonPath('permissions', ['*']);

        // Route bị chặn quyền (permission:products.view) vẫn truy cập được
        $this->getJson('/api/v1/products')->assertOk();

        // Unit: hasPermission luôn true
        $this->assertTrue($superAdmin->hasPermission('bat_ky.quyen_nao'));
    }

    #[TestDox('User thường không có role thì KHÔNG có quyền (không bị bypass nhầm)')]
    public function test_normal_user_without_role_has_no_permission(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/products')->assertForbidden();
        $this->assertFalse($user->hasPermission('products.view'));
    }
}
