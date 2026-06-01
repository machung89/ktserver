<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    private function makeCompany(array $attrs = []): Company
    {
        return Company::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'type' => CompanyType::Customer,
        ], $attrs));
    }

    #[TestDox('Lấy danh sách khách hàng/nhà cung cấp')]
    public function test_can_list_companies(): void
    {
        $this->makeCompany();
        $this->makeCompany();

        $this->getJson('/api/v1/companies')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'type']]]);
    }

    #[TestDox('Lọc danh sách theo loại (khách hàng / nhà cung cấp)')]
    public function test_can_filter_companies_by_type(): void
    {
        $this->makeCompany(['type' => CompanyType::Customer]);
        $this->makeCompany(['type' => CompanyType::Supplier]);

        $response = $this->getJson('/api/v1/companies?type=customer');

        $response->assertOk();
        $types = collect($response->json('data'))->pluck('type');
        $this->assertTrue($types->every(fn ($t) => ($t['value'] ?? $t) === 'customer'));
    }

    #[TestDox('Tạo mới khách hàng/nhà cung cấp')]
    public function test_can_create_company(): void
    {
        $this->postJson('/api/v1/companies', [
            'name' => 'Công ty ABC',
            'type' => 'customer',
            'phone' => '0901234567',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Công ty ABC');

        $this->assertDatabaseHas('companies', [
            'name' => 'Công ty ABC',
            'organization_id' => $this->organization->id,
        ]);
    }

    #[TestDox('Tạo mới yêu cầu tên và loại')]
    public function test_create_company_requires_name_and_type(): void
    {
        $this->postJson('/api/v1/companies', [])->assertUnprocessable();
        $this->postJson('/api/v1/companies', ['name' => 'Test'])->assertUnprocessable();
    }

    #[TestDox('Cập nhật thông tin khách hàng/nhà cung cấp')]
    public function test_can_update_company(): void
    {
        $company = $this->makeCompany();

        $this->putJson("/api/v1/companies/{$company->id}", ['name' => 'Tên mới'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Tên mới');
    }

    #[TestDox('Xóa khách hàng không có liên kết')]
    public function test_can_delete_company_with_no_links(): void
    {
        $company = $this->makeCompany();

        $this->deleteJson("/api/v1/companies/{$company->id}")->assertNoContent();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    #[TestDox('Không thể xóa khách hàng đang có đơn bán')]
    public function test_cannot_delete_company_with_sales_orders(): void
    {
        $company = $this->makeCompany();

        SalesOrder::factory()->create([
            'organization_id' => $this->organization->id,
            'company_id' => $company->id,
            'order_number' => 'BH000001',
        ]);

        $response = $this->deleteJson("/api/v1/companies/{$company->id}");

        $response->assertUnprocessable();
        $this->assertStringContainsString('đơn bán', $response->json('message'));
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    #[TestDox('Không thể xóa nhà cung cấp đang có đơn nhập')]
    public function test_cannot_delete_company_with_purchase_orders(): void
    {
        $company = $this->makeCompany(['type' => CompanyType::Supplier]);
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);

        PurchaseOrder::factory()->create([
            'organization_id' => $this->organization->id,
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'order_number' => 'NK000001',
        ]);

        $response = $this->deleteJson("/api/v1/companies/{$company->id}");

        $response->assertUnprocessable();
        $this->assertStringContainsString('đơn nhập', $response->json('message'));
    }
}
