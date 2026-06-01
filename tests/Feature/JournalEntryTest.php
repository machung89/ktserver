<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\CompanyType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    private Company $customer;

    private Company $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $accounts = [
            ['code' => '131',   'name' => 'Phải thu khách hàng',      'type' => AccountType::Asset],
            ['code' => '156',   'name' => 'Hàng hóa',                 'type' => AccountType::Asset],
            ['code' => '1331',  'name' => 'Thuế GTGT được khấu trừ', 'type' => AccountType::Asset],
            ['code' => '331',   'name' => 'Phải trả nhà cung cấp',    'type' => AccountType::Liability],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra',         'type' => AccountType::Liability],
            ['code' => '511',   'name' => 'Doanh thu bán hàng',       'type' => AccountType::Revenue],
            ['code' => '5212',  'name' => 'Hàng bán bị trả lại',     'type' => AccountType::Revenue],
            ['code' => '632',   'name' => 'Giá vốn hàng bán',        'type' => AccountType::Expense],
        ];

        foreach ($accounts as $acc) {
            Account::create(array_merge($acc, [
                'organization_id' => $this->organization->id,
                'is_active' => true,
            ]));
        }

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

        $this->product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'price' => 200000,
            'cost_price' => 120000,
            'is_active' => true,
        ]);

        Inventory::factory()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 100,
            'avg_cost' => 120000,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);
    }

    // ── HELPERS ─────────────────────────────────────────────────────────────

    private function createAndConfirmSale(int $qty = 2, int $price = 200000): array
    {
        $order = $this->postJson('/api/v1/sales', [
            'company_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => $qty,
                'unit_price' => $price,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 0,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/sales/{$order['id']}/confirm")->assertOk();

        return $order;
    }

    private function createAndConfirmPurchase(int $qty = 5, int $price = 120000): array
    {
        $order = $this->postJson('/api/v1/purchases', [
            'company_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => $qty,
                'unit_price' => $price,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/purchases/{$order['id']}/confirm")->assertOk();

        return $order;
    }

    private function getLines(int $entryId): Collection
    {
        return JournalEntryLine::with('account')
            ->where('journal_entry_id', $entryId)
            ->get();
    }

    /** Lấy bút toán mới nhất theo ID (chắc chắn hơn latest() theo timestamp) */
    private function latestEntry(): JournalEntry
    {
        return JournalEntry::orderByDesc('id')->firstOrFail();
    }

    private function assertLineExists(Collection $lines, string $code, string $side, float $amount): void
    {
        $field = $side === 'debit' ? 'debit_amount' : 'credit_amount';
        $match = $lines->first(fn ($l) => $l->account->code === $code && (float) $l->$field === $amount);
        $this->assertNotNull(
            $match,
            "Không tìm thấy dòng bút toán: TK {$code} {$side} {$amount}₫\n".
            'Các dòng hiện có: '.$lines->map(fn ($l) => "TK{$l->account->code} Nợ:{$l->debit_amount} Có:{$l->credit_amount}")->join(', ')
        );
    }

    // ── BÚT TOÁN BÁN HÀNG ────────────────────────────────────────────────────

    #[TestDox('Xác nhận đơn bán tạo bút toán doanh thu (Nợ 131 / Có 511)')]
    public function test_confirm_sale_creates_revenue_journal_entry(): void
    {
        $this->createAndConfirmSale(qty: 2, price: 200000);

        $entry = $this->latestEntry();
        $this->assertNotNull($entry, 'Không có bút toán nào được tạo');

        $lines = $this->getLines($entry->id);

        $this->assertLineExists($lines, '131', 'debit', 400000);
        $this->assertLineExists($lines, '511', 'credit', 400000);
    }

    #[TestDox('Xác nhận đơn bán tạo bút toán giá vốn (Nợ 632 / Có 156)')]
    public function test_confirm_sale_creates_cogs_journal_entry(): void
    {
        $this->createAndConfirmSale(qty: 2, price: 200000);

        $entry = $this->latestEntry();
        $lines = $this->getLines($entry->id);

        $this->assertLineExists($lines, '632', 'debit', 240000);
        $this->assertLineExists($lines, '156', 'credit', 240000);
    }

    #[TestDox('Bút toán bán hàng cân bằng Nợ = Có')]
    public function test_sale_journal_entry_is_balanced(): void
    {
        $this->createAndConfirmSale(qty: 3, price: 200000);

        $entry = $this->latestEntry();
        $lines = $this->getLines($entry->id);

        $totalDebit = $lines->sum(fn ($l) => (float) $l->debit_amount);
        $totalCredit = $lines->sum(fn ($l) => (float) $l->credit_amount);

        $this->assertEquals($totalDebit, $totalCredit, "Bút toán bán hàng không cân bằng: Nợ={$totalDebit} Có={$totalCredit}");
    }

    // ── BÚT TOÁN HOÀN TRẢ ───────────────────────────────────────────────────

    #[TestDox('Trả hàng bán tạo bút toán đảo chiều (Nợ 511 / Có 131)')]
    public function test_return_items_creates_reversed_revenue_entry(): void
    {
        $order = $this->createAndConfirmSale(qty: 3, price: 200000);
        $saleEntryId = $this->latestEntry()->id;

        $this->postJson("/api/v1/sales/{$order['id']}/return-items", [
            'return_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
            ]],
        ])->assertOk();

        $entry = JournalEntry::where('id', '>', $saleEntryId)->orderByDesc('id')->firstOrFail();
        $lines = $this->getLines($entry->id);

        $this->assertLineExists($lines, '511', 'debit', 200000);
        $this->assertLineExists($lines, '131', 'credit', 200000);
    }

    #[TestDox('Trả hàng bán tạo bút toán nhập kho lại (Nợ 156 / Có 632)')]
    public function test_return_items_creates_inventory_reversal_entry(): void
    {
        $order = $this->createAndConfirmSale(qty: 3, price: 200000);
        $saleEntryId = $this->latestEntry()->id;

        $this->postJson("/api/v1/sales/{$order['id']}/return-items", [
            'return_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
            ]],
        ])->assertOk();

        $entry = JournalEntry::where('id', '>', $saleEntryId)->orderByDesc('id')->firstOrFail();
        $lines = $this->getLines($entry->id);

        $this->assertLineExists($lines, '156', 'debit', 120000);
        $this->assertLineExists($lines, '632', 'credit', 120000);
    }

    #[TestDox('Bút toán hoàn trả cân bằng Nợ = Có')]
    public function test_return_journal_entry_is_balanced(): void
    {
        $order = $this->createAndConfirmSale(qty: 3, price: 200000);
        $saleEntryId = $this->latestEntry()->id;

        $this->postJson("/api/v1/sales/{$order['id']}/return-items", [
            'return_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 2,
            ]],
        ])->assertOk();

        $entry = JournalEntry::where('id', '>', $saleEntryId)->orderByDesc('id')->firstOrFail();
        $lines = $this->getLines($entry->id);

        $totalDebit = $lines->sum(fn ($l) => (float) $l->debit_amount);
        $totalCredit = $lines->sum(fn ($l) => (float) $l->credit_amount);

        $this->assertEquals($totalDebit, $totalCredit, "Bút toán hoàn trả không cân bằng: Nợ={$totalDebit} Có={$totalCredit}");
    }

    #[TestDox('Hoàn trả không dùng tài khoản 5212')]
    public function test_return_does_not_use_account_5212(): void
    {
        $order = $this->createAndConfirmSale(qty: 2, price: 200000);
        $saleEntryId = $this->latestEntry()->id;

        $this->postJson("/api/v1/sales/{$order['id']}/return-items", [
            'return_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
            ]],
        ])->assertOk();

        $entry = JournalEntry::where('id', '>', $saleEntryId)->orderByDesc('id')->firstOrFail();
        $lines = $this->getLines($entry->id);

        $has5212 = $lines->contains(fn ($l) => $l->account->code === '5212');
        $this->assertFalse($has5212, 'Bút toán hoàn trả không được dùng TK 5212');
    }

    // ── BÚT TOÁN NHẬP HÀNG ───────────────────────────────────────────────────

    #[TestDox('Xác nhận đơn nhập tạo bút toán nhập kho (Nợ 156 / Có 331)')]
    public function test_confirm_purchase_creates_inventory_journal_entry(): void
    {
        $this->createAndConfirmPurchase(qty: 5, price: 120000);

        $entry = $this->latestEntry();
        $this->assertNotNull($entry, 'Không có bút toán nào được tạo');

        $lines = $this->getLines($entry->id);

        $this->assertLineExists($lines, '156', 'debit', 600000);
        $this->assertLineExists($lines, '331', 'credit', 600000);
    }

    #[TestDox('Bút toán nhập hàng cân bằng Nợ = Có')]
    public function test_purchase_journal_entry_is_balanced(): void
    {
        $this->createAndConfirmPurchase(qty: 10, price: 120000);

        $entry = $this->latestEntry();
        $lines = $this->getLines($entry->id);

        $totalDebit = $lines->sum(fn ($l) => (float) $l->debit_amount);
        $totalCredit = $lines->sum(fn ($l) => (float) $l->credit_amount);

        $this->assertEquals($totalDebit, $totalCredit, "Bút toán nhập hàng không cân bằng: Nợ={$totalDebit} Có={$totalCredit}");
    }

    // ── BÚT TOÁN THỦ CÔNG ────────────────────────────────────────────────────

    #[TestDox('Tạo bút toán thủ công hợp lệ')]
    public function test_can_create_manual_journal_entry(): void
    {
        $this->postJson('/api/v1/journal-entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'Điều chỉnh số dư đầu kỳ',
            'lines' => [
                ['account_code' => '131', 'description' => 'Nợ phải thu', 'debit' => 500000, 'credit' => 0],
                ['account_code' => '511', 'description' => 'Doanh thu',   'debit' => 0, 'credit' => 500000],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('journal_entries', [
            'description' => 'Điều chỉnh số dư đầu kỳ',
            'organization_id' => $this->organization->id,
            'is_posted' => true,
        ]);
    }

    #[TestDox('Không thể tạo bút toán thủ công khi Nợ ≠ Có')]
    public function test_cannot_create_unbalanced_manual_entry(): void
    {
        $this->postJson('/api/v1/journal-entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'Bút toán sai',
            'lines' => [
                ['account_code' => '131', 'description' => '', 'debit' => 500000, 'credit' => 0],
                ['account_code' => '511', 'description' => '', 'debit' => 0, 'credit' => 300000],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('journal_entries', ['description' => 'Bút toán sai']);
    }

    #[TestDox('Bút toán thủ công yêu cầu ít nhất 2 dòng')]
    public function test_manual_entry_requires_at_least_two_lines(): void
    {
        $this->postJson('/api/v1/journal-entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'Chỉ một dòng',
            'lines' => [
                ['account_code' => '131', 'description' => '', 'debit' => 500000, 'credit' => 0],
            ],
        ])->assertUnprocessable();
    }

    #[TestDox('Số bút toán tự động tăng theo định dạng BT-XXXXXX')]
    public function test_entry_number_auto_increments(): void
    {
        $this->postJson('/api/v1/journal-entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'BT đầu tiên',
            'lines' => [
                ['account_code' => '131', 'description' => '', 'debit' => 100000, 'credit' => 0],
                ['account_code' => '511', 'description' => '', 'debit' => 0, 'credit' => 100000],
            ],
        ])->assertCreated();

        $this->postJson('/api/v1/journal-entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'BT thứ hai',
            'lines' => [
                ['account_code' => '131', 'description' => '', 'debit' => 200000, 'credit' => 0],
                ['account_code' => '511', 'description' => '', 'debit' => 0, 'credit' => 200000],
            ],
        ])->assertCreated();

        $entries = JournalEntry::orderBy('id')->get();
        $this->assertMatchesRegularExpression('/^BT-\d{6}$/', $entries->first()->entry_number);
        $this->assertNotEquals($entries->first()->entry_number, $entries->last()->entry_number);
    }
}
