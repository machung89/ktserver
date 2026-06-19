<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\Tour;
use Illuminate\Support\Facades\DB;

class TourService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function confirm(Tour $tour): Tour
    {
        return DB::transaction(function () use ($tour) {
            $tour = Tour::with('services')->lockForUpdate()->find($tour->id);

            $tour->update(['status' => 'confirmed']);

            $lines = $this->buildJournalLines($tour);

            $this->journalEntryService->create(
                description: "Xác nhận tour - {$tour->tour_number}",
                entryDate: $tour->start_date->toDateString(),
                reference: $tour,
                lines: $lines,
            );

            return $tour->fresh(['customer', 'createdBy', 'services.supplier']);
        });
    }

    public function cancel(Tour $tour): Tour
    {
        return DB::transaction(function () use ($tour) {
            $tour->update(['status' => 'cancelled']);

            $this->journalEntryService->deleteByReference($tour);

            return $tour;
        });
    }

    public function syncJournalEntry(Tour $tour): void
    {
        $entry = JournalEntry::where('reference_type', Tour::class)
            ->where('reference_id', $tour->id)
            ->first();

        if (! $entry) {
            return;
        }

        $tour->load('services');

        $entry->update(['entry_date' => $tour->start_date->toDateString()]);

        $this->journalEntryService->updateLines($entry, $this->buildJournalLines($tour));
    }

    /**
     * @return array<int, array{account_code: string, description: string, debit: float, credit: float}>
     */
    private function buildJournalLines(Tour $tour): array
    {
        // VND: làm tròn về đồng nguyên; các vế suy ra từ cùng giá trị đã làm tròn để luôn cân
        $totalRevenue = round((float) $tour->total_amount);
        $taxAmount = round((float) $tour->tax_amount);
        $netRevenue = $totalRevenue - $taxAmount;

        if ($taxAmount > 0) {
            $lines = [
                [
                    'account_code' => '131',
                    'description' => "Phải thu KH - {$tour->tour_number}",
                    'debit' => $totalRevenue,
                    'credit' => 0,
                ],
                [
                    'account_code' => '511',
                    'description' => "Doanh thu dịch vụ tour - {$tour->tour_number}",
                    'debit' => 0,
                    'credit' => $netRevenue,
                ],
                [
                    'account_code' => '3331',
                    'description' => "Thuế GTGT đầu ra - {$tour->tour_number}",
                    'debit' => 0,
                    'credit' => $taxAmount,
                ],
            ];
        } else {
            $lines = [
                [
                    'account_code' => '131',
                    'description' => "Phải thu KH - {$tour->tour_number}",
                    'debit' => $totalRevenue,
                    'credit' => 0,
                ],
                [
                    'account_code' => '511',
                    'description' => "Doanh thu dịch vụ tour - {$tour->tour_number}",
                    'debit' => 0,
                    'credit' => $totalRevenue,
                ],
            ];
        }

        // Giá vốn: 331 (Có) = 632 + 1331 (Nợ) — input VAT suy ra để cân tuyệt đối
        $totalCost = round($tour->services->sum(fn ($s) => (float) $s->cost));
        $totalBaseCost = round($tour->services->sum(
            fn ($s) => (float) $s->unit_price * (int) $s->quantity * (int) $s->days
        ));
        $totalInputVat = $totalCost - $totalBaseCost;

        if ($totalCost > 0) {
            if ($totalInputVat > 0) {
                $lines[] = [
                    'account_code' => '632',
                    'description' => "Giá vốn dịch vụ tour - {$tour->tour_number}",
                    'debit' => $totalBaseCost,
                    'credit' => 0,
                ];
                $lines[] = [
                    'account_code' => '1331',
                    'description' => "Thuế GTGT đầu vào dịch vụ tour - {$tour->tour_number}",
                    'debit' => $totalInputVat,
                    'credit' => 0,
                ];
                $lines[] = [
                    'account_code' => '331',
                    'description' => "Phải trả NCC dịch vụ tour - {$tour->tour_number}",
                    'debit' => 0,
                    'credit' => $totalCost,
                ];
            } else {
                $lines[] = [
                    'account_code' => '632',
                    'description' => "Giá vốn dịch vụ tour - {$tour->tour_number}",
                    'debit' => $totalCost,
                    'credit' => 0,
                ];
                $lines[] = [
                    'account_code' => '331',
                    'description' => "Phải trả NCC dịch vụ tour - {$tour->tour_number}",
                    'debit' => 0,
                    'credit' => $totalCost,
                ];
            }
        }

        return $lines;
    }
}
