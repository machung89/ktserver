<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class CompanyImportTest extends TestCase
{
    #[TestDox('Thêm đối tác: tự sinh mã khi để trống; chặn mã trùng')]
    public function test_create_company_autogenerates_and_unique_code(): void
    {
        $this->postJson('/api/v1/companies', ['name' => 'KH A', 'type' => 'customer'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'KH0001')->json();

        // Nhà cung cấp → tiền tố NCC
        $this->postJson('/api/v1/companies', ['name' => 'NCC A', 'type' => 'supplier'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'NCC0001');

        $this->postJson('/api/v1/companies', ['name' => 'KH B', 'type' => 'customer', 'code' => 'KH-X'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'KH-X');

        // Mã trùng → 422
        $this->postJson('/api/v1/companies', ['name' => 'KH C', 'type' => 'customer', 'code' => 'KH-X'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    #[TestDox('Import đối tác: bỏ trống mã → tự sinh')]
    public function test_import_autogenerates_code(): void
    {
        $res = $this->postJson('/api/v1/companies/import', [
            'rows' => [
                ['name' => 'Đối tác không mã', 'type' => 'customer'],
                ['name' => 'Đối tác có mã', 'type' => 'supplier', 'code' => 'NCC-9'],
            ],
        ])->assertOk()->json();

        $this->assertEquals(2, $res['success']);
        $this->assertDatabaseHas('companies', ['name' => 'Đối tác có mã', 'code' => 'NCC-9']);
        $this->assertDatabaseHas('companies', ['name' => 'Đối tác không mã', 'code' => 'KH0001']);
    }

    #[TestDox('Import đối tác: bỏ qua loại → mặc định Khách hàng')]
    public function test_import_defaults_type_to_customer_when_blank(): void
    {
        $res = $this->postJson('/api/v1/companies/import', [
            'rows' => [
                ['name' => 'KH không loại', 'type' => ''],
                ['name' => 'Đối tác thiếu cột loại'],
                ['name' => 'NCC rõ loại', 'type' => 'supplier'],
            ],
        ])->assertOk()->json();

        $this->assertEquals(3, $res['success']);
        $this->assertDatabaseHas('companies', [
            'name' => 'KH không loại', 'type' => 'customer', 'organization_id' => $this->organization->id,
        ]);
        $this->assertDatabaseHas('companies', [
            'name' => 'Đối tác thiếu cột loại', 'type' => 'customer',
        ]);
        $this->assertDatabaseHas('companies', [
            'name' => 'NCC rõ loại', 'type' => 'supplier',
        ]);
    }

    #[TestDox('Import đối tác: loại sai vẫn báo lỗi (không mặc định ngầm)')]
    public function test_import_rejects_invalid_type(): void
    {
        $res = $this->postJson('/api/v1/companies/import', [
            'rows' => [['name' => 'Sai loại', 'type' => 'xyz']],
        ])->assertOk()->json();

        $this->assertEquals(0, $res['success']);
        $this->assertEquals(1, $res['failed']);
    }

    #[TestDox('Import đối tác: mã trùng báo lỗi rõ ràng')]
    public function test_import_rejects_duplicate_code(): void
    {
        $res = $this->postJson('/api/v1/companies/import', [
            'rows' => [
                ['name' => 'Đối tác A', 'type' => 'customer', 'code' => 'KH-FIX'],
                ['name' => 'Đối tác B', 'type' => 'supplier', 'code' => 'KH-FIX'],
            ],
        ])->assertOk()->json();

        $this->assertEquals(1, $res['success']);
        $this->assertEquals(1, $res['failed']);
        $this->assertStringContainsString('Mã đối tác đã tồn tại', $res['errors'][0]['reason']);
    }

    #[TestDox('Import đối tác: email sai định dạng được bỏ qua, dòng vẫn nhập')]
    public function test_import_drops_invalid_email_but_keeps_row(): void
    {
        $res = $this->postJson('/api/v1/companies/import', [
            'rows' => [
                ['name' => 'KH email lỗi', 'type' => 'customer', 'email' => 'không-phải-email'],
                ['name' => 'KH email tốt', 'type' => 'customer', 'email' => 'good@example.com'],
            ],
        ])->assertOk()->json();

        $this->assertEquals(2, $res['success']);
        $this->assertDatabaseHas('companies', ['name' => 'KH email lỗi', 'email' => null]);
        $this->assertDatabaseHas('companies', ['name' => 'KH email tốt', 'email' => 'good@example.com']);
    }
}
