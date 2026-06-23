<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class EmployeePasswordTest extends TestCase
{
    private function roleId(): int
    {
        return Role::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->value('id');
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Nhân viên A',
            'email' => 'nva@test.com',
            'phone' => '0900000001',
            'is_active' => true,
            'role_ids' => [$this->roleId()],
        ], $override);
    }

    #[TestDox('Thêm nhân viên không nhập mật khẩu → tự sinh và bắt đổi lần đầu')]
    public function test_create_employee_autogenerates_password(): void
    {
        $res = $this->postJson('/api/v1/employees', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id'], 'generated_password']);

        $pw = $res->json('generated_password');
        $this->assertNotEmpty($pw);
        $this->assertGreaterThanOrEqual(8, strlen($pw));

        $this->assertDatabaseHas('users', [
            'email' => 'nva@test.com',
            'must_change_password' => true,
        ]);
    }

    #[TestDox('Thêm nhân viên: tự sinh mã NV khi để trống')]
    public function test_autogenerates_employee_code(): void
    {
        $this->postJson('/api/v1/employees', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.employee_code', 'NV0001');
    }

    #[TestDox('Thêm nhân viên: dùng mã NV nhập tay; chặn mã trùng trong tổ chức')]
    public function test_custom_and_unique_employee_code(): void
    {
        $this->postJson('/api/v1/employees', $this->payload(['employee_code' => 'KT01']))
            ->assertCreated()
            ->assertJsonPath('data.employee_code', 'KT01');

        // Trùng mã trong cùng tổ chức → 422
        $this->postJson('/api/v1/employees', $this->payload([
            'employee_code' => 'KT01',
            'email' => 'nvb@test.com',
            'phone' => '0900000002',
        ]))->assertUnprocessable()->assertJsonValidationErrors('employee_code');
    }

    #[TestDox('Đổi mật khẩu xóa cờ bắt buộc đổi (must_change_password)')]
    public function test_change_password_clears_flag(): void
    {
        $pw = $this->postJson('/api/v1/employees', $this->payload())
            ->assertCreated()->json('generated_password');

        $employee = User::where('email', 'nva@test.com')->firstOrFail();
        $this->assertTrue((bool) $employee->must_change_password);

        $this->actingAs($employee, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => $pw,
                'password' => 'newpass123',
                'password_confirmation' => 'newpass123',
            ])->assertOk();

        $this->assertFalse((bool) $employee->fresh()->must_change_password);
    }

    #[TestDox('/v1/me trả về cờ must_change_password')]
    public function test_me_exposes_flag(): void
    {
        $this->postJson('/api/v1/employees', $this->payload())->assertCreated();
        $employee = User::where('email', 'nva@test.com')->firstOrFail();

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('must_change_password', true);
    }
}
