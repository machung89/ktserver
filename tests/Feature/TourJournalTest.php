<?php

namespace Tests\Feature;

use App\Models\Tour;
use App\Models\TourService as TourServiceModel;
use App\Services\DefaultAccountsService;
use App\Services\TourService;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class TourJournalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->instance('orgId', $this->organization->id);

        $this->seed(SystemAccountsSeeder::class);
        app(DefaultAccountsService::class)->seedForOrganization($this->organization->id);
    }

    #[TestDox('Bút toán xác nhận tour cân dù doanh thu/thuế/giá vốn có phần lẻ (VND làm tròn)')]
    public function test_tour_confirm_journal_balanced_with_fractional_values(): void
    {
        $tour = Tour::create([
            'organization_id' => $this->organization->id,
            'tour_number' => 'TOUR-001',
            'name' => 'Tour kiểm thử',
            'start_date' => now()->toDateString(),
            'status' => 'quote',
            'vat_rate' => 8,
            'subtotal' => 92590.74,
            'tax_amount' => 7407.26,
            'total_amount' => 99998.0,
        ]);

        TourServiceModel::create([
            'tour_id' => $tour->id,
            'service_stage' => 'operating',
            'service_type' => 'hotel',
            'name' => 'Khách sạn',
            'unit_price' => 33333.33,
            'quantity' => 1,
            'days' => 1,
            'tax_rate' => 8,
            'cost' => 35999.99,
        ]);

        app(TourService::class)->confirm($tour);

        $sums = DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.reference_type', Tour::class)
            ->where('j.reference_id', $tour->id)
            ->selectRaw('COALESCE(SUM(l.debit_amount),0) as d, COALESCE(SUM(l.credit_amount),0) as c')
            ->first();

        $this->assertGreaterThan(0, (float) $sums->d);
        $this->assertEquals((float) $sums->d, (float) $sums->c, 'Tổng Nợ = Tổng Có (bút toán tour cân)');
    }
}
