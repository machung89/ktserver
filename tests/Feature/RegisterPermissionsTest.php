<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class RegisterPermissionsTest extends TestCase
{
    private function registerPayload(array $override = []): array
    {
        return array_merge([
            'company_name' => 'Công ty Test',
            'business_mode' => 'retail',
            'name' => 'Chủ Shop',
            'phone' => '0911222333',
            'email' => 'owner@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $override);
    }

    /** Lấy danh sách tên permission của 1 role trong 1 org */
    private function rolePermissionNames(int $orgId, string $roleName): array
    {
        $role = Role::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('name', $roleName)
            ->firstOrFail();

        return $role->permissions->pluck('name')->all();
    }

    #[TestDox('Đăng ký mới tạo tổ chức và người dùng quản trị')]
    public function test_register_creates_organization_and_admin_user(): void
    {
        $this->postJson('/api/register', $this->registerPayload())
            ->assertCreated()
            ->assertJsonStructure(['access_token', 'user' => ['id', 'name', 'organization']]);

        $this->assertDatabaseHas('organizations', ['name' => 'Công ty Test']);
        $this->assertDatabaseHas('users', ['email' => 'owner@test.com']);
    }

    #[TestDox('Đăng ký mới tự seed đầy đủ bộ permissions toàn cục')]
    public function test_register_seeds_all_global_permissions(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        // Sau đăng ký: bộ permissions toàn cục đã được tạo đầy đủ
        $this->assertGreaterThan(60, Permission::count());
        $this->assertTrue(Permission::where('name', 'journal.create')->exists());
        $this->assertTrue(Permission::where('name', 'sales.view_all')->exists());
    }

    #[TestDox('Người đăng ký được gán vai trò quản trị viên (admin)')]
    public function test_registered_user_gets_admin_role(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $user = User::withoutGlobalScopes()->where('email', 'owner@test.com')->firstOrFail();
        $roleNames = $user->roles->pluck('name')->all();

        $this->assertContains('admin', $roleNames);
    }

    #[TestDox('Vai trò admin có toàn bộ quyền hệ thống')]
    public function test_admin_role_has_all_permissions(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $org = Organization::where('name', 'Công ty Test')->firstOrFail();
        $adminPerms = $this->rolePermissionNames($org->id, 'admin');

        $this->assertEquals(Permission::count(), count($adminPerms), 'Admin phải có tất cả permissions');
    }

    #[TestDox('Vai trò kế toán có quyền tạo bút toán và xem lợi nhuận')]
    public function test_accountant_role_has_journal_and_profit_permissions(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $org = Organization::where('name', 'Công ty Test')->firstOrFail();
        $perms = $this->rolePermissionNames($org->id, 'accountant');

        $this->assertContains('journal.create', $perms);
        $this->assertContains('reports.view_profit', $perms);
        $this->assertContains('sales.view_all', $perms);
        $this->assertContains('assets.view', $perms);
    }

    #[TestDox('Vai trò thủ kho có quyền điều chỉnh tồn kho')]
    public function test_warehouse_staff_can_adjust_inventory(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $org = Organization::where('name', 'Công ty Test')->firstOrFail();
        $perms = $this->rolePermissionNames($org->id, 'warehouse_staff');

        $this->assertContains('inventory.adjust', $perms);
        $this->assertContains('purchases.confirm', $perms);
    }

    #[TestDox('Tạo đủ 4 vai trò mặc định cho tổ chức mới')]
    public function test_register_creates_four_default_roles(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $org = Organization::where('name', 'Công ty Test')->firstOrFail();
        $roleNames = Role::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->pluck('name')
            ->all();

        $this->assertEqualsCanonicalizing(
            ['admin', 'accountant', 'sales_staff', 'warehouse_staff'],
            $roleNames
        );
    }
}
