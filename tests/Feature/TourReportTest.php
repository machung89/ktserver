<?php

namespace Tests\Feature;

use App\Models\Tour;
use App\Models\TourService as TourServiceModel;
use App\Services\DefaultAccountsService;
use App\Services\TourService;
use Database\Seeders\SystemAccountsSeeder;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class TourReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->instance('orgId', $this->organization->id);
        $this->seed(SystemAccountsSeeder::class);
        app(DefaultAccountsService::class)->seedForOrganization($this->organization->id);
    }

    private function makeTour(string $number, float $total, float $tax = 0): Tour
    {
        return Tour::create([
            'organization_id' => $this->organization->id,
            'tour_number' => $number,
            'name' => "Tour {$number}",
            'start_date' => now()->toDateString(),
            'num_guests' => 10,
            'status' => 'quote',
            'vat_rate' => 0,
            'subtotal' => $total - $tax,
            'tax_amount' => $tax,
            'total_amount' => $total,
        ]);
    }

    private function addService(Tour $tour, float $unitPrice, float $cost): void
    {
        TourServiceModel::create([
            'tour_id' => $tour->id,
            'service_stage' => 'operating',
            'service_type' => 'hotel',
            'name' => 'Dịch vụ',
            'unit_price' => $unitPrice,
            'quantity' => 1,
            'days' => 1,
            'cost' => $cost,
        ]);
    }

    #[TestDox('Báo cáo tour: doanh thu/giá vốn/lợi nhuận đúng; chỉ gồm tour đã xác nhận')]
    public function test_tour_report_revenue_cost_profit(): void
    {
        $tour = $this->makeTour('TOUR-001', 10000000);
        $this->addService($tour, 3000000, 3000000);
        $this->addService($tour, 2000000, 2000000);
        app(TourService::class)->confirm($tour);

        // Tour nháp (chưa xác nhận) → KHÔNG vào báo cáo
        $this->makeTour('TOUR-002', 5000000);

        $res = $this->getJson('/api/v1/reports/tours')->assertOk()->json();

        $this->assertEquals(1, $res['tour_count']);
        $this->assertEquals(10, (int) $res['guest_count']);
        $this->assertEquals(10000000, (float) $res['total_revenue']);
        $this->assertEquals(5000000, (float) $res['total_cost']);
        $this->assertEquals(5000000, (float) $res['gross_profit']);

        $row = collect($res['data'])->firstWhere('tour_number', 'TOUR-001');
        $this->assertNotNull($row);
        $this->assertEquals(10000000, (float) $row['revenue']);
        $this->assertEquals(5000000, (float) $row['cost']);
        $this->assertEquals(5000000, (float) $row['profit']);
        $this->assertEquals(50.0, (float) $row['margin']);
    }

    #[TestDox('Báo cáo tour: doanh thu thuần loại trừ VAT, giá vốn chưa gồm VAT đầu vào')]
    public function test_tour_report_excludes_vat(): void
    {
        $tour = $this->makeTour('TOUR-VAT', 10800000, 800000); // thuần 10tr + VAT 800k
        $this->addService($tour, 4000000, 4320000);            // base 4tr + VAT đầu vào 320k
        app(TourService::class)->confirm($tour);

        $res = $this->getJson('/api/v1/reports/tours')->assertOk()->json();

        $this->assertEquals(10000000, (float) $res['total_revenue']); // không gồm VAT đầu ra
        $this->assertEquals(4000000, (float) $res['total_cost']);     // không gồm VAT đầu vào
        $this->assertEquals(6000000, (float) $res['gross_profit']);
    }
}
