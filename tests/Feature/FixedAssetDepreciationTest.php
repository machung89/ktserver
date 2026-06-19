<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedAsset;
use App\Services\DefaultAccountsService;
use App\Services\FixedAssetService;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class FixedAssetDepreciationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->instance('orgId', $this->organization->id);
        $this->seed(SystemAccountsSeeder::class);
        app(DefaultAccountsService::class)->seedForOrganization($this->organization->id);
    }

    #[TestDox('Khấu hao: Σ các năm = nguyên giá (năm cuối ôm phần dư), mỗi năm đồng nguyên, bút toán cân')]
    public function test_depreciation_reconciles_to_cost(): void
    {
        $expense = Account::where('code', '642')->firstOrFail();

        // 10tr / 3 năm = 3.333.333,33 → không chia hết
        $asset = FixedAsset::create([
            'organization_id' => $this->organization->id,
            'code' => 'TSCD-TEST',
            'name' => 'Máy tính',
            'expense_account_id' => $expense->id,
            'purchase_date' => '2024-01-01',
            'depreciation_start_date' => '2024-01-01',
            'cost' => 10000000,
            'residual_value' => 0,
            'useful_life_years' => 3,
            'accumulated_depreciation_account_code' => '214',
            'status' => 'active',
        ]);

        $total = 0.0;
        foreach ([2024, 2025, 2026] as $year) {
            $dep = app(FixedAssetService::class)->postDepreciation($asset, $year);
            $amount = (float) $dep->amount;
            $total += $amount;

            $this->assertEquals(round($amount), $amount, "Khấu hao năm {$year} phải là đồng nguyên");

            $sums = DB::table('journal_entry_lines as l')
                ->where('l.journal_entry_id', $dep->journal_entry_id)
                ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')
                ->first();
            $this->assertEquals((float) $sums->d, (float) $sums->c, "Bút toán khấu hao năm {$year} cân");
        }

        $this->assertEquals(10000000.0, $total, 'Σ khấu hao = nguyên giá (năm cuối ôm phần dư làm tròn)');
    }
}
