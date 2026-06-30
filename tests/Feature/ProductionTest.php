<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\DefaultAccountsService;
use App\Services\ProductionService;
use App\Services\SalesOrderService;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ProductionTest extends TestCase
{
    private Warehouse $warehouse;

    private Product $matA;

    private Product $matB;

    private Product $finished;

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance('orgId', $this->organization->id);
        $this->seed(SystemAccountsSeeder::class);
        app(DefaultAccountsService::class)->seedForOrganization($this->organization->id);

        $this->warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);
        $this->matA = Product::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true]);
        $this->matB = Product::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true]);
        $this->finished = Product::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true]);

        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $this->matA->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'avg_cost' => 10000, 'stock_value' => 1000000, 'reserved_quantity' => 0, 'min_quantity' => 0]);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $this->matB->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'avg_cost' => 5000, 'stock_value' => 500000, 'reserved_quantity' => 0, 'min_quantity' => 0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createDraft(): array
    {
        return $this->postJson('/api/v1/production-orders', [
            'product_id' => $this->finished->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'production_date' => now()->toDateString(),
            'materials' => [
                ['product_id' => $this->matA->id, 'quantity' => 20],
                ['product_id' => $this->matB->id, 'quantity' => 10],
            ],
            'costs' => [
                ['type' => 'labor', 'name' => 'Nhân công', 'amount' => 100000, 'credit_account_code' => '334'],
                ['type' => 'overhead', 'name' => 'Điện nước SX', 'amount' => 50000, 'credit_account_code' => '331'],
            ],
        ])->assertCreated()->json('data');
    }

    private function ledger(string $code, int $orderId): object
    {
        return DB::table('journal_entry_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', ProductionOrder::class)
            ->where('j.reference_id', $orderId)
            ->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')
            ->first();
    }

    #[TestDox('Hoàn thành lệnh SX: xuất NVL, nhập kho thành phẩm theo giá thành NVL+nhân công+SX chung, bút toán cân')]
    public function test_complete_production_costs_and_journal(): void
    {
        $draft = $this->createDraft();

        $order = app(ProductionService::class)->complete(ProductionOrder::find($draft['id']));

        // Giá thành: NVL = 20×10.000 + 10×5.000 = 250.000; NC 100.000; SXC 50.000; tổng 400.000; đơn vị 40.000
        $this->assertEquals(250000.0, (float) $order->material_cost);
        $this->assertEquals(100000.0, (float) $order->labor_cost);
        $this->assertEquals(50000.0, (float) $order->overhead_cost);
        $this->assertEquals(400000.0, (float) $order->total_cost);
        $this->assertEquals(40000.0, (float) $order->unit_cost);
        $this->assertEquals('completed', $order->status);

        // Tồn NVL giảm
        $this->assertEquals(80.0, (float) Inventory::where('product_id', $this->matA->id)->value('quantity'));
        $this->assertEquals(90.0, (float) Inventory::where('product_id', $this->matB->id)->value('quantity'));

        // Tồn thành phẩm tăng, giá vốn = giá thành đơn vị
        $fin = Inventory::where('product_id', $this->finished->id)->first();
        $this->assertEquals(10.0, (float) $fin->quantity);
        $this->assertEquals(40000.0, (float) $fin->avg_cost);
        $this->assertEquals(400000.0, (float) $fin->stock_value);

        // Bút toán VAS
        $this->assertEquals(250000.0, (float) $this->ledger('621', $order->id)->d, '621 Nợ = CP NVL');
        $this->assertEquals(100000.0, (float) $this->ledger('622', $order->id)->d, '622 Nợ = CP nhân công');
        $this->assertEquals(50000.0, (float) $this->ledger('627', $order->id)->d, '627 Nợ = CP SX chung');
        $this->assertEquals(400000.0, (float) $this->ledger('154', $order->id)->d, '154 nhận kết chuyển 400.000');
        $this->assertEquals(400000.0, (float) $this->ledger('154', $order->id)->c, '154 kết chuyển sang 155/156');
        $this->assertEquals(400000.0, (float) $this->ledger('156', $order->id)->d, '156 Nợ = nhập kho thành phẩm');
        $this->assertEquals(250000.0, (float) $this->ledger('156', $order->id)->c, '156 Có = xuất NVL');
        $this->assertEquals(100000.0, (float) $this->ledger('334', $order->id)->c, '334 Có = lương phải trả');
        $this->assertEquals(50000.0, (float) $this->ledger('331', $order->id)->c, '331 Có = phải trả NCC SXC');

        // Tổng Nợ = Tổng Có
        $sums = DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', ProductionOrder::class)->where('j.reference_id', $order->id)
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')->first();
        $this->assertEquals((float) $sums->d, (float) $sums->c, 'Bút toán sản xuất cân');
    }

    #[TestDox('Hủy lệnh SX đã hoàn thành: hoàn tồn NVL, rút thành phẩm, xóa bút toán')]
    public function test_cancel_reverses_inventory_and_journal(): void
    {
        $draft = $this->createDraft();
        $service = app(ProductionService::class);
        $order = $service->complete(ProductionOrder::find($draft['id']));

        $service->cancel(ProductionOrder::find($order->id));

        // Tồn NVL hoàn lại, thành phẩm về 0
        $this->assertEquals(100.0, (float) Inventory::where('product_id', $this->matA->id)->value('quantity'));
        $this->assertEquals(100.0, (float) Inventory::where('product_id', $this->matB->id)->value('quantity'));
        $this->assertEquals(0.0, (float) Inventory::where('product_id', $this->finished->id)->value('quantity'));

        // Bút toán bị xóa
        $count = DB::table('journal_entries')
            ->where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)->count();
        $this->assertEquals(0, $count);
        $this->assertEquals('cancelled', ProductionOrder::find($order->id)->status);
    }

    #[TestDox('Giá thành lẻ: stock_value thành phẩm = đúng tổng giá thành (bất biến = TK 156)')]
    public function test_finished_stock_value_matches_total_cost_when_not_divisible(): void
    {
        // 1 NVL giá 10.000 → tổng giá thành 10.000; sản xuất 3 → đơn giá 3.333,33 (không chia hết)
        $draft = $this->postJson('/api/v1/production-orders', [
            'product_id' => $this->finished->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 3,
            'production_date' => now()->toDateString(),
            'materials' => [['product_id' => $this->matA->id, 'quantity' => 1]],
        ])->assertCreated()->json('data');

        $order = app(ProductionService::class)->complete(ProductionOrder::find($draft['id']));

        $fin = Inventory::where('product_id', $this->finished->id)->first();
        $this->assertEquals(3.0, (float) $fin->quantity);
        // Không bị lệch do làm tròn: stock_value = tổng giá thành chính xác
        $this->assertEquals(10000.0, (float) $fin->stock_value);
        $this->assertEquals(10000.0, (float) $order->total_cost);
        // Nợ 156 (nhập thành phẩm) = 10.000, khớp giá trị tồn ghi tăng (không lệch làm tròn)
        $this->assertEquals(10000.0, (float) $this->ledger('156', $order->id)->d);
    }

    #[TestDox('Chặn hoàn thành khi NVL không đủ tồn (không cho phép tồn âm)')]
    public function test_complete_blocked_when_material_insufficient(): void
    {
        $lowMat = Product::factory()->create(['organization_id' => $this->organization->id, 'is_active' => true]);
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $lowMat->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 5, 'avg_cost' => 1000, 'stock_value' => 5000, 'reserved_quantity' => 0, 'min_quantity' => 0]);

        $draft = $this->postJson('/api/v1/production-orders', [
            'product_id' => $this->finished->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'production_date' => now()->toDateString(),
            'materials' => [['product_id' => $lowMat->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/production-orders/{$draft['id']}/complete")->assertStatus(422);

        // Không đổi: vẫn nháp, tồn NVL nguyên vẹn
        $this->assertEquals('draft', ProductionOrder::find($draft['id'])->status);
        $this->assertEquals(5.0, (float) Inventory::where('product_id', $lowMat->id)->value('quantity'));
    }

    #[TestDox('Chặn hủy lệnh khi thành phẩm đã được bán/sử dụng')]
    public function test_cancel_blocked_when_finished_consumed(): void
    {
        $draft = $this->createDraft();
        $order = app(ProductionService::class)->complete(ProductionOrder::find($draft['id']));

        // Giả lập đã bán/dùng bớt thành phẩm: chỉ còn 3 / 10
        Inventory::where('product_id', $this->finished->id)->update(['quantity' => 3]);

        $this->postJson("/api/v1/production-orders/{$order->id}/cancel")->assertStatus(422);

        // Không đổi: vẫn hoàn thành, tồn thành phẩm giữ nguyên
        $this->assertEquals('completed', ProductionOrder::find($order->id)->status);
        $this->assertEquals(3.0, (float) Inventory::where('product_id', $this->finished->id)->value('quantity'));
        $this->assertEquals(80.0, (float) Inventory::where('product_id', $this->matA->id)->value('quantity'), 'NVL không bị hoàn nhầm');
    }

    #[TestDox('Loại hình sản xuất: bán thành phẩm trừ TỒN THÀNH PHẨM (không trừ NVL dù có BOM)')]
    public function test_manufacturing_sale_deducts_finished_not_ingredients(): void
    {
        $this->organization->update(['settings' => array_merge($this->organization->settings ?? [], ['business_mode' => 'manufacturing'])]);

        // Thành phẩm có công thức (BOM) — chỉ dùng cho lệnh SX, KHÔNG dùng khi bán
        Recipe::create(['organization_id' => $this->organization->id, 'product_id' => $this->finished->id, 'type' => 'recipe', 'yield_quantity' => 1])
            ->ingredients()->createMany([
                ['ingredient_id' => $this->matA->id, 'quantity' => 2],
                ['ingredient_id' => $this->matB->id, 'quantity' => 1],
            ]);

        // Tồn thành phẩm sẵn (giả lập đã sản xuất nhập kho)
        Inventory::factory()->create(['organization_id' => $this->organization->id, 'product_id' => $this->finished->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 10, 'avg_cost' => 40000, 'stock_value' => 400000, 'reserved_quantity' => 0, 'min_quantity' => 0]);

        $customer = Company::factory()->create(['organization_id' => $this->organization->id, 'type' => 'customer']);

        $order = $this->postJson('/api/v1/sales', [
            'company_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->finished->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 3,
                'unit_price' => 60000,
                'tax_rate' => 0,
            ]],
        ])->assertCreated()->json('data');

        app(SalesOrderService::class)->confirm(SalesOrder::find($order['id']));

        // Trừ TỒN THÀNH PHẨM, KHÔNG đụng nguyên liệu
        $this->assertEquals(7.0, (float) Inventory::where('product_id', $this->finished->id)->value('quantity'));
        $this->assertEquals(100.0, (float) Inventory::where('product_id', $this->matA->id)->value('quantity'));
        $this->assertEquals(100.0, (float) Inventory::where('product_id', $this->matB->id)->value('quantity'));

        // Giá vốn = giá vốn thành phẩm (40.000), không phải tổng NVL
        $this->assertDatabaseHas('sales_order_items', [
            'sales_order_id' => $order['id'],
            'product_id' => $this->finished->id,
            'cost_price' => 40000,
        ]);
    }

    #[TestDox('Tự suy NVL theo công thức (BOM) khi không gửi danh sách NVL')]
    public function test_materials_autofilled_from_recipe(): void
    {
        Recipe::create(['organization_id' => $this->organization->id, 'product_id' => $this->finished->id, 'type' => 'recipe', 'yield_quantity' => 1])
            ->ingredients()->createMany([
                ['ingredient_id' => $this->matA->id, 'quantity' => 2],
                ['ingredient_id' => $this->matB->id, 'quantity' => 1],
            ]);

        $draft = $this->postJson('/api/v1/production-orders', [
            'product_id' => $this->finished->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'production_date' => now()->toDateString(),
        ])->assertCreated()->json('data');

        $order = ProductionOrder::with('materials')->find($draft['id']);
        // 5 thành phẩm × (2 A + 1 B) = 10 A + 5 B
        $this->assertEquals(10.0, (float) $order->materials->firstWhere('product_id', $this->matA->id)->quantity);
        $this->assertEquals(5.0, (float) $order->materials->firstWhere('product_id', $this->matB->id)->quantity);
    }
}
