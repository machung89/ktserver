<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\CompanyType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
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

    #[TestDox('Không đọc được tài nguyên của tổ chức khác qua route-model-binding')]
    public function test_cannot_read_foreign_resources(): void
    {
        $org = $this->otherOrg->id;

        $warehouse = Warehouse::factory()->create(['organization_id' => $org]);
        $account = Account::create(['organization_id' => $org, 'code' => 'TST', 'name' => 'Test', 'type' => AccountType::Asset, 'is_active' => true]);
        $employee = User::factory()->create(['organization_id' => $org]);
        $sale = SalesOrder::factory()->create(['organization_id' => $org]);
        $supplier = Company::factory()->create(['organization_id' => $org, 'type' => CompanyType::Supplier]);
        $purchase = PurchaseOrder::factory()->create(['organization_id' => $org, 'company_id' => $supplier->id, 'warehouse_id' => $warehouse->id]);
        $payment = Payment::create(['organization_id' => $org, 'payment_number' => 'PT-T1', 'type' => 'receipt', 'amount' => 1000, 'payment_date' => now()->toDateString(), 'account_id' => $account->id]);

        $this->getJson("/api/v1/warehouses/{$warehouse->id}")->assertNotFound();
        $this->getJson("/api/v1/accounts/{$account->id}")->assertNotFound();
        $this->getJson("/api/v1/employees/{$employee->id}")->assertNotFound();
        $this->getJson("/api/v1/sales/{$sale->id}")->assertNotFound();
        $this->getJson("/api/v1/purchases/{$purchase->id}")->assertNotFound();
        $this->getJson("/api/v1/payments/{$payment->id}")->assertNotFound();
    }

    #[TestDox('Không gỡ được phân bổ thanh toán của tổ chức khác (route lồng)')]
    public function test_cannot_delete_foreign_allocation(): void
    {
        $org = $this->otherOrg->id;
        $account = Account::create(['organization_id' => $org, 'code' => 'TST2', 'name' => 'Test2', 'type' => AccountType::Asset, 'is_active' => true]);
        $payment = Payment::create(['organization_id' => $org, 'payment_number' => 'PT-T2', 'type' => 'receipt', 'amount' => 10000, 'payment_date' => now()->toDateString(), 'account_id' => $account->id]);
        $sale = SalesOrder::factory()->create(['organization_id' => $org]);
        $alloc = PaymentAllocation::create([
            'organization_id' => $org,
            'payment_id' => $payment->id,
            'sales_order_id' => $sale->id,
            'amount' => 10000,
        ]);

        $status = $this->deleteJson("/api/v1/payments/allocations/{$alloc->id}")->getStatusCode();
        $this->assertContains($status, [403, 404], 'Phải bị chặn (403/404), không được xóa');
        $this->assertDatabaseHas('payment_allocations', ['id' => $alloc->id]);
    }
}
