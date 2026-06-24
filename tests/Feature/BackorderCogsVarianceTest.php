<?php

namespace Tests\Feature;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\DefaultAccountsService;
use App\Services\InventoryTransactionService;
use App\Services\SalesOrderService;
use Database\Seeders\SystemAccountsSeeder;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class BackorderCogsVarianceTest extends TestCase
{
    private Company $customer;

    private Company $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Cho phép bán khi hết hàng (bán trước - nhập sau)
        $this->organization->update(['settings' => ['allow_negative_stock' => true]]);

        $this->customer = Company::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => CompanyType::Customer,
        ]);
        $this->supplier = Company::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => CompanyType::Supplier,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        // Giá vốn tạm tính (fallback) = 80.000 khi chưa từng nhập
        $this->product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'price' => 150000,
            'cost_price' => 80000,
            'is_active' => true,
        ]);
        Inventory::factory()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0,
            'avg_cost' => 0,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        $this->seed(SystemAccountsSeeder::class);
        app(DefaultAccountsService::class)->seedForOrganization($this->organization->id);
    }

    private function sellOne(): void
    {
        $order = $this->postJson('/api/v1/sales', [
            'company_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
                'unit_price' => 150000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ])->assertCreated()->json('data');

        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));
    }

    private function buyOneAt(float $unitPrice): PurchaseOrder
    {
        return $this->buy(1, $unitPrice);
    }

    private function buy(int $qty, float $unitPrice): PurchaseOrder
    {
        $order = $this->postJson('/api/v1/purchases', [
            'company_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/purchases/{$order['id']}/confirm")->assertOk();

        return PurchaseOrder::findOrFail($order['id']);
    }

    private function inventory(): Inventory
    {
        return Inventory::where([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'organization_id' => $this->organization->id,
        ])->first();
    }

    #[TestDox('Bán trước (tồn âm) ghi nhận giá vốn tạm tính vào stock_value')]
    public function test_oversell_records_provisional_cogs(): void
    {
        $this->sellOne();

        $inv = $this->inventory();
        $this->assertEquals(-1, (float) $inv->quantity);
        // Giá vốn tạm = product.cost_price = 80.000 → stock_value = -80.000
        $this->assertEquals(-80000, (float) $inv->stock_value);
    }

    #[TestDox('Nhập sau giá cao hơn: kết chuyển tăng giá vốn (Nợ 632 / Có 156)')]
    public function test_backorder_purchase_higher_price_posts_variance(): void
    {
        $this->sellOne();         // giá vốn tạm 80.000
        $order = $this->buyOneAt(100000); // giá nhập thật 100.000 → chênh +20.000

        $inv = $this->inventory();
        $this->assertEquals(0, (float) $inv->quantity);
        $this->assertEquals(0, (float) $inv->stock_value); // 0 × avg

        $variance = JournalEntry::where('reference_type', PurchaseOrder::class)
            ->where('reference_id', $order->id)
            ->where('description', 'like', '%chênh lệch giá vốn%')
            ->with('lines.account')
            ->first();

        $this->assertNotNull($variance, 'Phải có bút toán kết chuyển chênh lệch giá vốn');
        $cogs = $variance->lines->firstWhere('account.code', '632');
        $inventoryLine = $variance->lines->firstWhere('account.code', '156');
        $this->assertEquals(20000, (float) $cogs->debit_amount);
        $this->assertEquals(20000, (float) $inventoryLine->credit_amount);
    }

    #[TestDox('Nhập sau giá thấp hơn: kết chuyển giảm giá vốn (Nợ 156 / Có 632)')]
    public function test_backorder_purchase_lower_price_reverses_variance(): void
    {
        $this->sellOne();        // giá vốn tạm 80.000
        $order = $this->buyOneAt(70000); // giá nhập thật 70.000 → chênh -10.000

        $variance = JournalEntry::where('reference_type', PurchaseOrder::class)
            ->where('reference_id', $order->id)
            ->where('description', 'like', '%chênh lệch giá vốn%')
            ->with('lines.account')
            ->first();

        $this->assertNotNull($variance);
        $cogs = $variance->lines->firstWhere('account.code', '632');
        $inventoryLine = $variance->lines->firstWhere('account.code', '156');
        $this->assertEquals(10000, (float) $inventoryLine->debit_amount);
        $this->assertEquals(10000, (float) $cogs->credit_amount);
    }

    #[TestDox('Nhập đúng giá vốn tạm: không phát sinh bút toán chênh lệch')]
    public function test_no_variance_when_price_matches(): void
    {
        $this->sellOne();        // giá vốn tạm 80.000
        $order = $this->buyOneAt(80000); // bằng giá tạm → không chênh

        $variance = JournalEntry::where('reference_type', PurchaseOrder::class)
            ->where('reference_id', $order->id)
            ->where('description', 'like', '%chênh lệch giá vốn%')
            ->exists();

        $this->assertFalse($variance);
    }

    #[TestDox('Hoàn đơn nhập thường về nháp: stock_value trở về đúng (không lệch)')]
    public function test_revert_normal_purchase_restores_stock_value(): void
    {
        $order = $this->buyOneAt(100000); // nhập vào kho trống, không bán trước → không chênh

        $inv = $this->inventory();
        $this->assertEquals(1, (float) $inv->quantity);
        $this->assertEquals(100000, (float) $inv->stock_value);

        $this->postJson("/api/v1/purchases/{$order->id}/revert-to-draft")->assertOk();

        $inv->refresh();
        $this->assertEquals(0, (float) $inv->quantity);
        $this->assertEquals(0, (float) $inv->stock_value); // không để sót giá trị
    }

    #[TestDox('Đảo phiếu nhập cũ: avg_cost tính lại đúng theo lô còn lại')]
    public function test_revert_older_purchase_recomputes_avg_cost(): void
    {
        $a = $this->buy(10, 100000); // lô 1: 100k
        $this->buy(10, 200000);      // lô 2: 200k → avg = 150k

        $inv = $this->inventory();
        $this->assertEquals(150000, (float) $inv->avg_cost);
        $this->assertEquals(3000000, (float) $inv->stock_value);

        // Đảo lô 1 (rẻ hơn) → còn lại 10 cái của lô 200k
        $this->postJson("/api/v1/purchases/{$a->id}/revert-to-draft")->assertOk();

        $inv->refresh();
        $this->assertEquals(10, (float) $inv->quantity);
        $this->assertEquals(200000, (float) $inv->avg_cost);     // KHÔNG còn 150k
        $this->assertEquals(2000000, (float) $inv->stock_value);
        // avg & giá trị tồn nhất quán → không có chênh lệch tồn ảo
        $variance = app(InventoryTransactionService::class)->cogsVariance($inv);
        $this->assertEquals(0.0, $variance);
    }

    #[TestDox('Không cho hoàn về nháp đơn đã kết chuyển chênh lệch giá vốn')]
    public function test_cannot_revert_purchase_with_cogs_variance(): void
    {
        $this->sellOne();
        $order = $this->buyOneAt(100000); // phát sinh chênh lệch

        $this->postJson("/api/v1/purchases/{$order->id}/revert-to-draft")
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('status');
    }
}
