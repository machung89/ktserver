<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\Organization;
use App\Models\Product;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class CrossTenantIsolationTest extends TestCase
{
    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otherOrg = Organization::create(['name' => 'Org B', 'is_active' => true]);
    }

    #[TestDox('Không đọc được đối tác của tổ chức khác (IDOR)')]
    public function test_cannot_read_other_org_company(): void
    {
        $foreign = Company::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'type' => CompanyType::Customer,
        ]);

        $this->getJson("/api/v1/companies/{$foreign->id}")->assertNotFound();
    }

    #[TestDox('Không sửa được đối tác của tổ chức khác')]
    public function test_cannot_update_other_org_company(): void
    {
        $foreign = Company::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'type' => CompanyType::Customer,
        ]);

        $this->putJson("/api/v1/companies/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->assertDatabaseMissing('companies', ['id' => $foreign->id, 'name' => 'Hacked']);
    }

    #[TestDox('Không xóa được đối tác của tổ chức khác')]
    public function test_cannot_delete_other_org_company(): void
    {
        $foreign = Company::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'type' => CompanyType::Customer,
        ]);

        $this->deleteJson("/api/v1/companies/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('companies', ['id' => $foreign->id]);
    }

    #[TestDox('Không đọc được sản phẩm của tổ chức khác')]
    public function test_cannot_read_other_org_product(): void
    {
        $foreign = Product::factory()->create(['organization_id' => $this->otherOrg->id]);
        $this->getJson("/api/v1/products/{$foreign->id}")->assertNotFound();
    }

    #[TestDox('Danh sách đối tác chỉ trả về của tổ chức mình')]
    public function test_company_list_scoped_to_own_org(): void
    {
        Company::factory()->create(['organization_id' => $this->otherOrg->id, 'type' => CompanyType::Customer]);
        Company::factory()->create(['organization_id' => $this->organization->id, 'type' => CompanyType::Customer]);

        $res = $this->getJson('/api/v1/companies')->assertOk()->json('data');
        foreach ($res as $row) {
            $this->assertDatabaseHas('companies', ['id' => $row['id'], 'organization_id' => $this->organization->id]);
        }
    }
}
