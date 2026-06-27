<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DefaultAccountsService;
use App\Services\PaymentService;
use App\Services\SalesOrderService;
use Database\Seeders\SystemAccountsSeeder;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    private Company $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Company::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => CompanyType::Customer,
        ]);

        $this->warehouse = Warehouse::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'price' => 100000,
            'cost_price' => 60000,
            'is_active' => true,
        ]);

        Inventory::factory()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        // Hệ thống tài khoản kế toán chuẩn (cần cho bút toán khi xác nhận đơn)
        $this->seed(SystemAccountsSeeder::class);
        app(DefaultAccountsService::class)->seedForOrganization($this->organization->id);
    }

    private function validPayload(array $override = []): array
    {
        return array_merge([
            'company_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 2,
                'unit_price' => 100000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ], $override);
    }

    #[TestDox('Tạo đơn bán hàng')]
    public function test_can_create_sales_order(): void
    {
        $response = $this->postJson('/api/v1/sales', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->assertEquals(200000, (float) $response->json('data.total_amount'));

        $this->assertDatabaseHas('sales_orders', [
            'organization_id' => $this->organization->id,
            'company_id' => $this->customer->id,
        ]);
    }

    #[TestDox('Tạo đơn bán yêu cầu có sản phẩm')]
    public function test_create_requires_items(): void
    {
        $this->postJson('/api/v1/sales', ['order_date' => now()->toDateString()])
            ->assertUnprocessable();
    }

    #[TestDox('Đơn trả hàng liên kết với đơn gốc qua original_order_id')]
    public function test_return_order_links_to_original(): void
    {
        $original = $this->postJson('/api/v1/sales', $this->validPayload())
            ->assertCreated()
            ->json('data');

        $return = $this->postJson('/api/v1/sales', $this->validPayload([
            'is_return_order' => true,
            'original_order_id' => $original['id'],
            'notes' => "Đơn {$original['order_number']} trả hàng",
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => -2,
                'unit_price' => 100000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ]))->assertCreated()->json('data');

        $this->assertDatabaseHas('sales_orders', [
            'id' => $return['id'],
            'original_order_id' => $original['id'],
        ]);

        // Đơn trả hàng tự động hoàn thành ngay khi tạo
        $this->assertEquals('completed', $return['status']['value'] ?? $return['status']);
        $this->assertDatabaseHas('sales_orders', ['id' => $return['id'], 'status' => 'completed']);
    }

    public function test_full_return_keeps_original_cost_and_standard(): void
    {
        $order = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));

        $return = $this->postJson('/api/v1/sales', $this->validPayload([
            'is_return_order' => true,
            'original_order_id' => $order['id'],
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => -2,
                'unit_price' => 100000,
                'cost_price' => 77777,      // giá vốn đơn gốc
                'standard_price' => 55555,  // chia giá đơn gốc
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ]))->assertCreated()->json('data');

        // Đơn trả tự hoàn thành & GIỮ giá vốn + chia giá theo đơn gốc (confirm không ghi đè theo avg)
        $this->assertDatabaseHas('sales_order_items', [
            'sales_order_id' => $return['id'],
            'cost_price' => 77777,
            'standard_price' => 55555,
        ]);
    }

    public function test_sale_order_allows_negative_quantity_line(): void
    {
        // Đơn bán thường có dòng âm (đổi/điều chỉnh) — được chấp nhận
        $order = $this->postJson('/api/v1/sales', $this->validPayload([
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => -1,
                'unit_price' => 100000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ]))->assertCreated()->json('data');

        $this->assertEquals(-100000, (float) $order['total_amount']);

        // Xác nhận: dòng âm = nhập trả lại kho (100 → 101), bút toán cân (confirm không ném lỗi)
        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));
        $inv = Inventory::where([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'organization_id' => $this->organization->id,
        ])->first();
        $this->assertEquals(101, (float) $inv->quantity);

        // Số lượng 0 vẫn bị từ chối
        $this->postJson('/api/v1/sales', $this->validPayload([
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 0,
                'unit_price' => 100000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ]))->assertUnprocessable();
    }

    public function test_only_returns_filter_and_count(): void
    {
        $original = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        $return = $this->postJson('/api/v1/sales', $this->validPayload([
            'is_return_order' => true,
            'original_order_id' => $original['id'],
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => -1,
                'unit_price' => 100000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ]))->json('data');

        // Tab "Trả hàng": chỉ hiện đơn trả
        $res = $this->getJson('/api/v1/sales?only_returns=1')->assertOk()->json('data');
        $ids = collect($res)->pluck('id')->all();
        $this->assertContains($return['id'], $ids);
        $this->assertNotContains($original['id'], $ids);

        $this->getJson('/api/v1/sales/counts')->assertOk()->assertJsonPath('returns', 1);
    }

    #[TestDox('Đơn gốc hiển thị has_return = true khi đã có đơn trả')]
    public function test_original_order_shows_has_return_true(): void
    {
        $original = $this->postJson('/api/v1/sales', $this->validPayload())
            ->assertCreated()->json('data');

        $this->postJson('/api/v1/sales', $this->validPayload([
            'is_return_order' => true,
            'original_order_id' => $original['id'],
            'notes' => 'Trả hàng',
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => -1,
                'unit_price' => 100000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ]))->assertCreated();

        $this->getJson("/api/v1/sales/{$original['id']}")
            ->assertOk()
            ->assertJsonPath('data.has_return', true);
    }

    #[TestDox('Đơn chưa có trả hàng hiển thị has_return = false')]
    public function test_order_without_return_shows_has_return_false(): void
    {
        $order = $this->postJson('/api/v1/sales', $this->validPayload())
            ->assertCreated()->json('data');

        $this->getJson("/api/v1/sales/{$order['id']}")
            ->assertOk()
            ->assertJsonPath('data.has_return', false);
    }

    #[TestDox('Sản phẩm ngừng bán không xuất hiện trong danh sách active_only')]
    public function test_inactive_product_not_in_active_only_list(): void
    {
        $inactive = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/products?active_only=1');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($inactive->id, $ids);
    }

    #[TestDox('Lấy danh sách đơn bán hàng')]
    public function test_can_list_sales_orders(): void
    {
        $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated();

        $this->getJson('/api/v1/sales')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'order_number', 'status', 'total_amount']]]);
    }

    #[TestDox('Sửa nhanh người tạo & ngày bán; đồng bộ ngày bút toán')]
    public function test_quick_update_creator_and_date(): void
    {
        $order = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));

        $other = User::factory()->create(['organization_id' => $this->organization->id]);
        $newDate = '2026-01-15';

        $this->patchJson("/api/v1/sales/{$order['id']}/quick-update", [
            'order_date' => $newDate,
            'created_by' => $other->id,
        ])->assertOk()->assertJsonPath('data.created_by', $other->id);

        $this->assertDatabaseHas('sales_orders', [
            'id' => $order['id'],
            'created_by' => $other->id,
            'order_date' => $newDate,
        ]);

        // Ngày hạch toán của bút toán liên quan cũng được đồng bộ
        $this->assertDatabaseHas('journal_entries', [
            'reference_type' => SalesOrder::class,
            'reference_id' => $order['id'],
            'entry_date' => $newDate,
        ]);
    }

    public function test_invoice_status_default_and_quick_update(): void
    {
        $order = $this->postJson('/api/v1/sales', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.invoice_status', 'not_issued')
            ->json('data');

        $this->patchJson("/api/v1/sales/{$order['id']}/quick-update", [
            'invoice_status' => 'issued',
        ])->assertOk()->assertJsonPath('data.invoice_status', 'issued');

        $this->assertDatabaseHas('sales_orders', [
            'id' => $order['id'],
            'invoice_status' => 'issued',
        ]);

        // Giá trị ngoài enum bị từ chối
        $this->patchJson("/api/v1/sales/{$order['id']}/quick-update", [
            'invoice_status' => 'xyz',
        ])->assertUnprocessable();
    }

    public function test_bulk_invoice_status_update(): void
    {
        $a = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        $b = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');

        $this->postJson('/api/v1/sales/bulk-invoice-status', [
            'ids' => [$a['id'], $b['id']],
            'invoice_status' => 'issued',
        ])->assertOk()->assertJsonPath('updated', 2);

        $this->assertDatabaseHas('sales_orders', ['id' => $a['id'], 'invoice_status' => 'issued']);
        $this->assertDatabaseHas('sales_orders', ['id' => $b['id'], 'invoice_status' => 'issued']);
    }

    public function test_bulk_assign_shipper_by_code(): void
    {
        $order = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        $shipper = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->postJson('/api/v1/sales/bulk-shipper-by-code', [
            'codes' => [$order['order_number'], 'KHONG-CO'],
            'shipper_id' => $shipper->id,
        ])->assertOk()
            ->assertJsonPath('summary.assigned', 1)
            ->assertJsonPath('summary.not_found', 1);

        $this->assertDatabaseHas('sales_orders', [
            'id' => $order['id'],
            'shipper_id' => $shipper->id,
        ]);

        // Sửa nhanh cũng gắn được & gỡ được (null)
        $this->patchJson("/api/v1/sales/{$order['id']}/quick-update", ['shipper_id' => null])
            ->assertOk()->assertJsonPath('data.shipper_id', null);
    }

    public function test_shipper_assign_reports_remaining_unscanned_drafts(): void
    {
        // 3 đơn nháp, chỉ quét 1 → còn 2 đơn nháp chưa quét
        $a = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        $this->postJson('/api/v1/sales', $this->validPayload());
        $this->postJson('/api/v1/sales', $this->validPayload());
        $shipper = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->postJson('/api/v1/sales/bulk-shipper-by-code', [
            'codes' => [$a['order_number']],
            'shipper_id' => $shipper->id,
        ])->assertOk()
            ->assertJsonPath('summary.assigned', 1)
            ->assertJsonPath('summary.remaining_draft', 2);
    }

    public function test_remaining_draft_counts_only_unscanned_drafts(): void
    {
        // 3 đơn nháp + 1 đơn đã xác nhận. Quét 1 đơn nháp + 1 đơn đã xác nhận.
        $draftA = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        $this->postJson('/api/v1/sales', $this->validPayload()); // nháp B
        $this->postJson('/api/v1/sales', $this->validPayload()); // nháp C
        $confirmed = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        app(SalesOrderService::class)->confirm(SalesOrder::find($confirmed['id']));
        $shipper = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->postJson('/api/v1/sales/bulk-shipper-by-code', [
            'codes' => [$draftA['order_number'], $confirmed['order_number']],
            'shipper_id' => $shipper->id,
        ])->assertOk()
            ->assertJsonPath('summary.assigned', 2)
            // 3 nháp − 1 nháp đã quét = 2 (đơn xác nhận đã quét KHÔNG ảnh hưởng số nháp)
            ->assertJsonPath('summary.remaining_draft', 2);
    }

    public function test_filter_by_shipper_including_unassigned(): void
    {
        $withShipper = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        $noShipper = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        $shipper = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->patchJson("/api/v1/sales/{$withShipper['id']}/quick-update", ['shipper_id' => $shipper->id])->assertOk();

        // Lọc theo nhân viên giao
        $ids = collect($this->getJson("/api/v1/sales?shipper_id={$shipper->id}")->json('data'))->pluck('id')->all();
        $this->assertContains($withShipper['id'], $ids);
        $this->assertNotContains($noShipper['id'], $ids);

        // Lọc "Chưa có" (none)
        $idsNone = collect($this->getJson('/api/v1/sales?shipper_id=none')->json('data'))->pluck('id')->all();
        $this->assertContains($noShipper['id'], $idsNone);
        $this->assertNotContains($withShipper['id'], $idsNone);
    }

    public function test_invoice_export_returns_38_columns(): void
    {
        $order = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');

        $res = $this->postJson('/api/v1/sales/invoice-export', ['ids' => [$order['id']]])
            ->assertOk()->json();

        $this->assertCount(38, $res['headers']);
        $this->assertEquals('Hình thức bán hàng', $res['headers'][0]);
        $this->assertEquals('Diễn giải(Xuất HĐ)', $res['headers'][37]);

        // 1 đơn × 1 dòng hàng = 1 dòng
        $this->assertCount(1, $res['rows']);
        $row = $res['rows'][0];
        $this->assertEquals($order['order_number'], $row[7]);  // Số chứng từ
        $this->assertEquals($this->product->code, $row[20]);   // Mã hàng
        $this->assertEquals('131', $row[23]);                   // TK Nợ
        $this->assertEquals('5111', $row[24]);                  // TK Doanh thu
        $this->assertEquals(2, $row[26]);                       // Số lượng

        // Đơn giá 100.000 đã gồm VAT, mặc định 8% → tách ngược:
        // Đơn giá chưa thuế = round(100000/1.08)=92593; Thành tiền = 185186; thuế 8%; tiền thuế = 200000-185186
        $this->assertEquals(92593, $row[27]);                   // Đơn giá (chưa thuế)
        $this->assertEquals(185186, $row[28]);                  // Thành tiền (chưa thuế)
        $this->assertEquals(8, $row[29]);                       // % thuế GTGT (mặc định)
        $this->assertEquals(14814, $row[30]);                   // Tiền thuế GTGT
        $this->assertEquals('33311', $row[31]);                 // TK thuế GTGT
        // Thành tiền + tiền thuế = tiền khách trả (gồm VAT), bỏ chiết khấu
        $this->assertEquals(200000, (float) $row[28] + (float) $row[30]);
    }

    public function test_invoice_export_ignores_discount(): void
    {
        // Dòng hàng có chiết khấu 10% → amount lưu = 180.000, nhưng export phải bỏ qua chiết khấu
        $order = $this->postJson('/api/v1/sales', $this->validPayload([
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 2,
                'unit_price' => 100000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 10,
            ]],
        ]))->json('data');

        $row = $this->postJson('/api/v1/sales/invoice-export', ['ids' => [$order['id']]])
            ->assertOk()->json('rows')[0];

        // Tính theo đơn giá × SL = 200.000 (gồm VAT), KHÔNG theo amount đã chiết khấu (180.000)
        $this->assertEquals(185186, $row[28]);                  // Thành tiền chưa thuế
        $this->assertEquals(14814, $row[30]);                   // Tiền thuế
        $this->assertEquals(200000, (float) $row[28] + (float) $row[30]);
    }

    public function test_filter_by_invoice_status(): void
    {
        $a = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        $b = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');

        $this->patchJson("/api/v1/sales/{$a['id']}/quick-update", ['invoice_status' => 'proposed'])->assertOk();

        $res = $this->getJson('/api/v1/sales?invoice_status=proposed')->assertOk()->json('data');
        $ids = collect($res)->pluck('id')->all();

        $this->assertContains($a['id'], $ids);
        $this->assertNotContains($b['id'], $ids);
    }

    #[TestDox('Sửa nhanh không nhận người tạo ngoài tổ chức')]
    public function test_quick_update_rejects_foreign_user(): void
    {
        $order = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        $foreign = User::factory()->create(); // tổ chức khác

        $this->patchJson("/api/v1/sales/{$order['id']}/quick-update", [
            'created_by' => $foreign->id,
        ])->assertUnprocessable();
    }

    #[TestDox('Sửa nhanh tôn trọng phạm vi xem: NV không có "xem tất cả" không sửa được đơn người khác')]
    public function test_quick_update_respects_view_scope(): void
    {
        // Đơn do admin tạo
        $order = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');

        // Nhân viên chỉ có sales.view + sales.edit (không có sales.view_all)
        $view = Permission::create(['name' => 'sales.view', 'display_name' => 'Xem đơn bán', 'module' => 'sales']);
        $edit = Permission::create(['name' => 'sales.edit', 'display_name' => 'Sửa đơn bán', 'module' => 'sales']);
        $role = Role::create(['organization_id' => $this->organization->id, 'name' => 'staff', 'display_name' => 'NV bán']);
        $role->permissions()->attach([$view->id, $edit->id]);
        $staff = User::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true]);
        $staff->roles()->attach($role->id);
        $this->actingAs($staff, 'sanctum');

        // Không xem được đơn của admin → không sửa nhanh được
        $this->patchJson("/api/v1/sales/{$order['id']}/quick-update", ['order_date' => '2026-02-02'])
            ->assertForbidden();
    }

    #[TestDox('Báo cáo doanh thu theo khách hàng')]
    public function test_sales_report_grouped_by_customer(): void
    {
        $order = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));

        $res = $this->getJson('/api/v1/reports/sales?group_by=customer')->assertOk()->json();

        $this->assertEquals(200000, (float) $res['total_revenue']);
        $row = collect($res['data'])->firstWhere('company_id', $this->customer->id);
        $this->assertNotNull($row);
        $this->assertEquals($this->customer->name, $row['customer_name']);
        $this->assertEquals(1, $row['order_count']);
        $this->assertEquals(200000, (float) $row['revenue']);
    }

    public function test_promotion_report_order_discount(): void
    {
        $promo = Promotion::create([
            'organization_id' => $this->organization->id,
            'name' => 'Giảm 10% toàn đơn',
            'type' => 'order_discount',
            'scope' => 'all',
            'conditions' => [],
            'is_active' => true,
        ]);

        $order = $this->postJson('/api/v1/sales', $this->validPayload())->json('data');
        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));

        SalesOrder::withoutGlobalScopes()->where('id', $order['id'])->update([
            'promotion_id' => $promo->id,
            'discount_amount' => 20000,
        ]);

        $res = $this->getJson('/api/v1/reports/promotions')->assertOk()->json();

        $row = collect($res['data'])->firstWhere('id', $promo->id);
        $this->assertNotNull($row);
        $this->assertEquals('order_discount', $row['type']);
        $this->assertEquals(1, $row['order_count']);
        $this->assertEquals(20000, (float) $row['discount_total']);
        $this->assertEquals(20000, (float) $res['total_discount']);
    }

    #[TestDox('Thu hoàn ứng NCC: phiếu thu đối ứng 331 ghi Nợ 111 / Có 331')]
    public function test_receipt_with_counter_account(): void
    {
        $cash = Account::where('organization_id', $this->organization->id)->where('code', 'like', '111%')->firstOrFail();
        $ap = Account::where('organization_id', $this->organization->id)->where('code', '331')->firstOrFail();

        $p = $this->postJson('/api/v1/payments', [
            'type' => 'receipt',
            'company_id' => $this->customer->id,
            'account_id' => $cash->id,
            'expense_account_id' => $ap->id,
            'payment_date' => now()->toDateString(),
            'amount' => 150000,
            'description' => 'Thu hoàn ứng NCC',
        ])->assertCreated()->json('data');

        $credit331 = \DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.reference_type', Payment::class)->where('j.reference_id', $p['id'])
            ->where('a.code', '331')->where('l.credit_amount', 150000)->exists();
        $this->assertTrue($credit331, 'Phải có dòng Có 331 = 150.000');
    }

    #[TestDox('Chi trả lại tiền khách ứng trước: Nợ 131 / Có 111 và giảm quỹ ứng')]
    public function test_refund_customer_advance_reduces_pool(): void
    {
        $cash = Account::where('organization_id', $this->organization->id)->where('code', 'like', '111%')->firstOrFail();
        $ar = Account::where('organization_id', $this->organization->id)->where('code', '131')->firstOrFail();
        $svc = app(PaymentService::class);

        // Khách ứng trước 500.000
        $this->postJson('/api/v1/payments', [
            'type' => 'receipt',
            'company_id' => $this->customer->id,
            'account_id' => $cash->id,
            'payment_date' => now()->toDateString(),
            'amount' => 500000,
            'is_advance' => true,
        ])->assertCreated();
        $this->assertEquals(500000.0, $svc->availableAdvance($this->customer->id, $this->organization->id));

        // Hoàn lại 200.000 (phiếu chi đối ứng 131)
        $p = $this->postJson('/api/v1/payments', [
            'type' => 'payment',
            'company_id' => $this->customer->id,
            'account_id' => $cash->id,
            'expense_account_id' => $ar->id,
            'payment_date' => now()->toDateString(),
            'amount' => 200000,
            'description' => 'Trả lại tiền khách ứng trước',
        ])->assertCreated()->json('data');

        // Quỹ ứng còn 300.000
        $this->assertEquals(300000.0, $svc->availableAdvance($this->customer->id, $this->organization->id));

        // Bút toán chi: Nợ 131 = 200.000
        $debit131 = \DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.reference_type', Payment::class)->where('j.reference_id', $p['id'])
            ->where('a.code', '131')->where('l.debit_amount', 200000)->exists();
        $this->assertTrue($debit131, 'Phải có dòng Nợ 131 = 200.000');
    }

    #[TestDox('Import đơn bán Excel: áp đúng chiết khấu + thuế, tạo đơn nháp')]
    public function test_bulk_import_applies_tax_and_discount(): void
    {
        $res = $this->postJson('/api/v1/sales/import', [
            'orders' => [[
                'company_id' => $this->customer->id,
                'order_date' => now()->toDateString(),
                'items' => [[
                    'product_id' => $this->product->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => 2,
                    'unit_price' => 100000,
                    'discount_type' => 'percent',
                    'discount_value' => 10,
                    'tax_rate' => 8,
                    'cost_price' => 60000,
                ]],
            ]],
        ])->assertOk()->json();

        $this->assertEquals(1, $res['success']);

        $order = SalesOrder::where('company_id', $this->customer->id)->latest('id')->first();
        // 2 × 100.000 = 200.000; −10% = 180.000; VAT 8% = 14.400; tổng 194.400
        $this->assertEquals(180000, (float) $order->subtotal);
        $this->assertEquals(14400, (float) $order->tax_amount);
        $this->assertEquals(194400, (float) $order->total_amount);
        $this->assertEquals('draft', $order->status->value);
        // Giữ chỗ tồn kho (reserved) chứ chưa trừ tồn
        $this->assertEquals(2.0, (float) Inventory::where(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id])->value('reserved_quantity'));
    }

    #[TestDox('Bán combo: giá vốn = tổng giá vốn thành phần, trừ kho thành phần (không trừ combo)')]
    public function test_combo_sale_cost_and_inventory(): void
    {
        // 2 sản phẩm thành phần có tồn + giá vốn TB
        $compA = Product::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true]);
        $compB = Product::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true]);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $compA->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'avg_cost' => 10000, 'reserved_quantity' => 0, 'min_quantity' => 0]);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $compB->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'avg_cost' => 5000, 'reserved_quantity' => 0, 'min_quantity' => 0]);

        // SP combo: 2 x compA + 1 x compB
        $combo = Product::factory()->create(['organization_id' => $this->organization->id, 'price' => 50000, 'product_type' => 'product', 'is_active' => true]);
        $recipe = Recipe::create(['organization_id' => $this->organization->id, 'product_id' => $combo->id, 'type' => 'combo', 'yield_quantity' => 1]);
        $recipe->ingredients()->createMany([
            ['ingredient_id' => $compA->id, 'quantity' => 2],
            ['ingredient_id' => $compB->id, 'quantity' => 1],
        ]);

        // Tạo đơn bán 3 combo rồi xác nhận
        $order = $this->postJson('/api/v1/sales', $this->validPayload([
            'items' => [[
                'product_id' => $combo->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 3,
                'unit_price' => 50000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ]))->assertCreated()->json('data');

        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));

        // Giá vốn combo/đơn vị = 2*10000 + 1*5000 = 25000
        $this->assertDatabaseHas('sales_order_items', [
            'sales_order_id' => $order['id'],
            'product_id' => $combo->id,
            'cost_price' => 25000,
        ]);

        // Trừ kho thành phần: A 100-6=94 ; B 100-3=97
        $this->assertEquals(94.0, (float) Inventory::where('product_id', $compA->id)->value('quantity'));
        $this->assertEquals(97.0, (float) Inventory::where('product_id', $compB->id)->value('quantity'));

        // Combo không có tồn riêng (không bị trừ)
        $comboQty = Inventory::where('product_id', $combo->id)->value('quantity');
        $this->assertTrue($comboQty === null || (float) $comboQty === 0.0);

        // Bút toán: 632/156 = giá vốn thành phần (3*25000=75000); 511/131 = doanh thu (3*50000=150000)
        $ledger = fn (string $code) => \DB::table('journal_entry_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', SalesOrder::class)
            ->where('j.reference_id', $order['id'])
            ->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')
            ->first();

        $this->assertEquals(75000.0, (float) $ledger('632')->d, 'Nợ 632 = giá vốn thành phần');
        $this->assertEquals(75000.0, (float) $ledger('156')->c, 'Có 156 = giảm tồn kho thành phần');
        $this->assertEquals(150000.0, (float) $ledger('511')->c, 'Có 511 = doanh thu combo');
        $this->assertEquals(150000.0, (float) $ledger('131')->d, 'Nợ 131 = phải thu');
    }

    #[TestDox('Chiết khấu cấp đơn: lưu CK, xác nhận được và bút toán cân (511 = doanh thu thuần)')]
    public function test_order_discount_persisted_and_journal_balanced(): void
    {
        // 2 x 100.000 = 200.000, chiết khấu cấp đơn cố định 20.000 → tổng 180.000
        $order = $this->postJson('/api/v1/sales', $this->validPayload([
            'discount_type' => 'fixed',
            'discount_value' => 20000,
        ]))->assertCreated()->json('data');

        // CK được lưu thật vào DB
        $this->assertDatabaseHas('sales_orders', [
            'id' => $order['id'],
            'discount_type' => 'fixed',
            'discount_amount' => 20000,
            'subtotal' => 200000,
            'total_amount' => 180000,
        ]);

        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));

        // Bút toán cân + 511 = doanh thu thuần 180.000; 131 = 180.000
        $line = fn (string $code) => \DB::table('journal_entry_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', SalesOrder::class)->where('j.reference_id', $order['id'])
            ->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')->first();

        $this->assertEquals(180000.0, (float) $line('511')->c, '511 = doanh thu thuần (đã trừ CK)');
        $this->assertEquals(180000.0, (float) $line('131')->d, '131 = tổng phải thu');

        $sums = \DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', SalesOrder::class)->where('j.reference_id', $order['id'])
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')->first();
        $this->assertEquals((float) $sums->d, (float) $sums->c, 'Tổng Nợ = Tổng Có');
    }

    #[TestDox('Xác nhận đơn có tổng tiền lẻ (nguồn import chưa làm tròn) vẫn tạo bút toán cân')]
    public function test_confirm_with_fractional_total_keeps_journal_balanced(): void
    {
        $order = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');

        // Mô phỏng dữ liệu nguồn (Shopee/Tiktok cũ) chưa làm tròn: tổng tiền có phần lẻ .5
        \DB::table('sales_orders')->where('id', $order['id'])->update([
            'subtotal' => 89077.5,
            'total_amount' => 89077.5,
            'tax_amount' => 0,
        ]);

        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));

        $sums = \DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', SalesOrder::class)->where('j.reference_id', $order['id'])
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')->first();

        $this->assertEquals((float) $sums->d, (float) $sums->c, 'Tổng Nợ = Tổng Có dù tổng tiền có phần lẻ');
    }

    #[TestDox('Thu nợ gộp: phân bổ FIFO đơn cũ trước, đơn cuối trả 1 phần')]
    public function test_bulk_receipt_allocates_fifo(): void
    {
        // Đơn 1 cũ hơn (hôm qua) + đơn 2 mới hơn, mỗi đơn 200.000
        $o1 = $this->postJson('/api/v1/sales', $this->validPayload(['order_date' => now()->subDay()->toDateString()]))->assertCreated()->json('data');
        $o2 = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        app(SalesOrderService::class)->confirm(SalesOrder::find($o1['id']));
        app(SalesOrderService::class)->confirm(SalesOrder::find($o2['id']));

        $cash = Account::where('organization_id', $this->organization->id)->where('code', 'like', '111%')->firstOrFail();

        // Khách trả 250.000 → đơn 1 đủ 200.000, đơn 2 trả 50.000
        $res = $this->postJson('/api/v1/payments/bulk-receipt', [
            'company_id' => $this->customer->id,
            'account_id' => $cash->id,
            'payment_date' => now()->toDateString(),
            'amount' => 250000,
        ])->assertOk()->json();

        $this->assertEquals(2, $res['allocated_orders']);
        $this->assertEquals(250000, $res['allocated_amount']);
        $this->assertEquals(0, $res['advance']);
        $this->assertEquals(200000.0, (float) SalesOrder::find($o1['id'])->paid_amount, 'Đơn cũ được trả đủ trước');
        $this->assertEquals(50000.0, (float) SalesOrder::find($o2['id'])->paid_amount, 'Đơn mới trả phần còn lại');
        $this->assertEquals('paid', SalesOrder::find($o1['id'])->payment_status->value);
        $this->assertEquals('partial', SalesOrder::find($o2['id'])->payment_status->value);

        // Chỉ MỘT phiếu thu cho cả cục (dễ tra soát) + 2 dòng phân bổ
        $this->assertEquals(1, Payment::where('type', 'receipt')->count(), 'Một lần thu gộp = 1 phiếu thu');
        $this->assertEquals(2, PaymentAllocation::count());
        // Một bút toán duy nhất cho phiếu, cân Nợ = Có = 250.000
        $payment = Payment::where('type', 'receipt')->firstOrFail();
        $sums = \DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', Payment::class)->where('j.reference_id', $payment->id)
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')->first();
        $this->assertEquals(250000.0, (float) $sums->d);
        $this->assertEquals((float) $sums->d, (float) $sums->c, 'Bút toán phiếu thu gộp cân');

        // Lịch sử thanh toán trong chi tiết đơn KHÔNG trống dù thu gộp (đọc qua allocations)
        $detail = $this->getJson("/api/v1/sales/{$o1['id']}")->assertOk()->json('data');
        $this->assertCount(1, $detail['payments']);
        $this->assertEquals(200000.0, (float) $detail['payments'][0]['amount']);
        $this->assertTrue($detail['payments'][0]['allocated']);
    }

    #[TestDox('Xóa phiếu thu gộp: hoàn lại công nợ tất cả đơn đã phân bổ')]
    public function test_deleting_bulk_receipt_reverses_allocations(): void
    {
        $o1 = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        $o2 = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        app(SalesOrderService::class)->confirm(SalesOrder::find($o1['id']));
        app(SalesOrderService::class)->confirm(SalesOrder::find($o2['id']));

        $cash = Account::where('organization_id', $this->organization->id)->where('code', 'like', '111%')->firstOrFail();
        $this->postJson('/api/v1/payments/bulk-receipt', [
            'company_id' => $this->customer->id,
            'account_id' => $cash->id,
            'payment_date' => now()->toDateString(),
            'amount' => 400000,
        ])->assertOk();

        $payment = Payment::where('type', 'receipt')->firstOrFail();
        $this->deleteJson("/api/v1/payments/{$payment->id}")->assertNoContent();

        // Cả 2 đơn về lại chưa thanh toán, allocations bị xóa theo
        $this->assertEquals(0.0, (float) SalesOrder::find($o1['id'])->paid_amount);
        $this->assertEquals(0.0, (float) SalesOrder::find($o2['id'])->paid_amount);
        $this->assertEquals('unpaid', SalesOrder::find($o1['id'])->payment_status->value);
        $this->assertEquals(0, PaymentAllocation::count());
    }

    #[TestDox('Thu nợ gộp chọn tùy chỉnh: chỉ áp đúng đơn & số tiền đã chọn')]
    public function test_bulk_receipt_custom_allocation(): void
    {
        $o1 = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        $o2 = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        $o3 = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        foreach ([$o1, $o2, $o3] as $o) {
            app(SalesOrderService::class)->confirm(SalesOrder::find($o['id']));
        }

        $cash = Account::where('organization_id', $this->organization->id)->where('code', 'like', '111%')->firstOrFail();

        // Chọn tay: đơn 1 trả đủ 200k, đơn 3 trả một phần 50k, BỎ QUA đơn 2
        $res = $this->postJson('/api/v1/payments/bulk-receipt', [
            'company_id' => $this->customer->id,
            'account_id' => $cash->id,
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['sales_order_id' => $o1['id'], 'amount' => 200000],
                ['sales_order_id' => $o3['id'], 'amount' => 50000],
            ],
        ])->assertOk()->json();

        $this->assertEquals(2, $res['allocated_orders']);
        $this->assertEquals(250000, $res['allocated_amount']);
        $this->assertEquals('paid', SalesOrder::find($o1['id'])->payment_status->value);
        $this->assertEquals('unpaid', SalesOrder::find($o2['id'])->payment_status->value, 'Đơn không chọn không bị thu');
        $this->assertEquals('partial', SalesOrder::find($o3['id'])->payment_status->value);
        $this->assertEquals(50000.0, (float) SalesOrder::find($o3['id'])->paid_amount);
        $this->assertEquals(1, Payment::where('type', 'receipt')->count(), 'Vẫn 1 phiếu thu');
    }

    #[TestDox('Thu nợ gộp: tiền dư hơn tổng nợ → ghi nhận thu trước')]
    public function test_bulk_receipt_excess_becomes_advance(): void
    {
        $o1 = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        app(SalesOrderService::class)->confirm(SalesOrder::find($o1['id']));

        $cash = Account::where('organization_id', $this->organization->id)->where('code', 'like', '111%')->firstOrFail();

        // Tổng nợ 200.000, khách trả 300.000 → dư 100.000 thành credit (giữ trên CÙNG 1 phiếu)
        $res = $this->postJson('/api/v1/payments/bulk-receipt', [
            'company_id' => $this->customer->id,
            'account_id' => $cash->id,
            'payment_date' => now()->toDateString(),
            'amount' => 300000,
        ])->assertOk()->json();

        $this->assertEquals(200000, $res['allocated_amount']);
        $this->assertEquals(100000, $res['advance']);
        $this->assertEquals('paid', SalesOrder::find($o1['id'])->payment_status->value);

        // Mô hình hợp nhất: 1 lần khách đưa tiền = ĐÚNG 1 phiếu thu (đủ 300k), phần dư là credit
        $this->assertEquals(1, Payment::count(), 'Trả dư vẫn chỉ 1 phiếu thu');
        $this->assertEquals(300000.0, (float) Payment::firstOrFail()->amount);
        $this->assertEquals(100000.0, app(PaymentService::class)->availableAdvance($this->customer->id, $this->organization->id), 'Phần dư = credit khách');

        // Xóa phiếu → đảo toàn bộ (allocations + credit)
        $this->deleteJson('/api/v1/payments/'.Payment::firstOrFail()->id)->assertNoContent();
        $this->assertEquals(0, Payment::count());
        $this->assertEquals(0, PaymentAllocation::count());
        $this->assertEquals(0.0, app(PaymentService::class)->availableAdvance($this->customer->id, $this->organization->id));
        $this->assertEquals('unpaid', SalesOrder::find($o1['id'])->payment_status->value);
    }

    #[TestDox('Gỡ áp một phân bổ: đơn về chưa thanh toán, tiền quay lại credit khách')]
    public function test_unapply_allocation_returns_money_to_credit(): void
    {
        $cash = Account::where('organization_id', $this->organization->id)->where('code', 'like', '111%')->firstOrFail();

        // Nhận 300k thu trước, đơn 200k đã xác nhận, áp 200k vào đơn
        $this->postJson('/api/v1/payments', [
            'type' => 'receipt', 'company_id' => $this->customer->id, 'account_id' => $cash->id,
            'payment_date' => now()->toDateString(), 'amount' => 300000, 'is_advance' => true,
        ])->assertCreated();
        $o = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        app(SalesOrderService::class)->confirm(SalesOrder::find($o['id']));
        $this->postJson('/api/v1/payments', [
            'type' => 'receipt', 'company_id' => $this->customer->id, 'payment_date' => now()->toDateString(),
            'amount' => 200000, 'reference_type' => SalesOrder::class, 'reference_id' => $o['id'], 'is_advance' => true,
        ])->assertCreated();

        $this->assertEquals('paid', SalesOrder::find($o['id'])->payment_status->value);
        $this->assertEquals(100000.0, app(PaymentService::class)->availableAdvance($this->customer->id, $this->organization->id));

        // Gỡ áp khỏi đơn → đơn về unpaid, 200k quay lại credit (tổng credit = 300k), KHÔNG xóa phiếu thu
        $alloc = PaymentAllocation::firstOrFail();
        $this->deleteJson("/api/v1/payments/allocations/{$alloc->id}")->assertNoContent();

        $this->assertEquals(1, Payment::count(), 'Phiếu thu vẫn còn');
        $this->assertEquals(0, PaymentAllocation::count());
        $this->assertEquals('unpaid', SalesOrder::find($o['id'])->payment_status->value);
        $this->assertEquals(300000.0, app(PaymentService::class)->availableAdvance($this->customer->id, $this->organization->id), 'Tiền gỡ ra quay về credit');
    }

    #[TestDox('Dùng tiền thu trước cho đơn: phân bổ, KHÔNG tạo phiếu thu mới')]
    public function test_apply_advance_uses_allocation_not_new_receipt(): void
    {
        $cash = Account::where('organization_id', $this->organization->id)->where('code', 'like', '111%')->firstOrFail();

        // Nhận tiền thu trước 300.000 (chưa gắn đơn) → 1 phiếu thu + bút toán
        $this->postJson('/api/v1/payments', [
            'type' => 'receipt',
            'company_id' => $this->customer->id,
            'account_id' => $cash->id,
            'payment_date' => now()->toDateString(),
            'amount' => 300000,
            'is_advance' => true,
        ])->assertCreated();
        $this->assertEquals(1, Payment::count());
        $this->assertEquals(300000.0, app(PaymentService::class)->availableAdvance($this->customer->id, $this->organization->id));

        // Đơn 200.000 đã xác nhận
        $o = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        app(SalesOrderService::class)->confirm(SalesOrder::find($o['id']));

        // Dùng tiền thu trước áp vào đơn
        $this->postJson('/api/v1/payments', [
            'type' => 'receipt',
            'company_id' => $this->customer->id,
            'payment_date' => now()->toDateString(),
            'amount' => 200000,
            'reference_type' => SalesOrder::class,
            'reference_id' => $o['id'],
            'is_advance' => true,
        ])->assertCreated();

        // KHÔNG tạo phiếu thu thừa — vẫn chỉ 1 phiếu (phiếu nhận tiền trước), chỉ thêm 1 dòng phân bổ
        $this->assertEquals(1, Payment::count(), 'Dùng tiền thu trước không tạo phiếu thu thừa');
        $this->assertEquals(1, PaymentAllocation::count());
        $this->assertEquals('paid', SalesOrder::find($o['id'])->payment_status->value);
        $this->assertEquals(100000.0, app(PaymentService::class)->availableAdvance($this->customer->id, $this->organization->id), 'Tiền thu trước còn lại sau khi áp');
    }

    #[TestDox('Báo cáo doanh thu theo SP khớp với tổng đơn (phân bổ chiết khấu cấp đơn)')]
    public function test_product_revenue_report_reconciles_with_order_total(): void
    {
        // Đơn 1: 200.000 không CK
        $o1 = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        // Đơn 2: CK cấp đơn 20.000 → tổng 180.000
        $o2 = $this->postJson('/api/v1/sales', $this->validPayload([
            'discount_type' => 'fixed', 'discount_value' => 20000,
        ]))->assertCreated()->json('data');

        app(SalesOrderService::class)->confirm(SalesOrder::find($o1['id']));
        app(SalesOrderService::class)->confirm(SalesOrder::find($o2['id']));

        // Doanh thu báo cáo theo SP = tổng total_amount = 200.000 + 180.000 = 380.000 (không phải 400.000 gộp)
        $res = $this->getJson('/api/v1/reports/sales?group_by=product')->assertOk()->json();
        $this->assertEquals(380000.0, (float) $res['total_revenue']);
    }

    #[TestDox('Xác nhận hàng loạt theo mã vận đơn: nháp được xác nhận, trạng thái khác bỏ qua, mã sai báo không tìm thấy')]
    public function test_bulk_confirm_by_code(): void
    {
        // Đơn nháp + có mã vận đơn → sẽ được xác nhận
        $draft = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        \DB::table('sales_orders')->where('id', $draft['id'])->update(['tracking_number' => 'VD-001']);

        // Đơn đã ở trạng thái khác (completed) → bỏ qua
        $other = $this->postJson('/api/v1/sales', $this->validPayload())->assertCreated()->json('data');
        \DB::table('sales_orders')->where('id', $other['id'])->update(['status' => 'completed', 'tracking_number' => 'VD-002']);

        $response = $this->postJson('/api/v1/sales/bulk-confirm-by-code', [
            'codes' => ['VD-001', 'VD-002', 'KHONG-TON-TAI'],
        ])->assertOk();

        $response->assertJsonPath('summary.confirmed', 1)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('summary.not_found', 1);

        $this->assertDatabaseHas('sales_orders', [
            'id' => $draft['id'],
            'status' => 'confirmed',
        ]);
    }
}
