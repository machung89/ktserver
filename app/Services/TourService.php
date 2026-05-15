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

            $totalRevenue = (float) $tour->total_amount;
            $totalCost = $tour->services->sum(
                fn ($s) => (float) $s->unit_price * (int) $s->quantity * (int) $s->days
            );

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

            if ($totalCost > 0) {
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

            $this->journalEntryService->create(
                description: "Xác nhận tour - {$tour->tour_number}",
                entryDate: $tour->start_date->toDateString(),
                reference: $tour,
                lines: $lines,
            );

            return $tour->fresh(['customer', 'createdBy', 'services.supplier']);
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
        $totalRevenue = (float) $tour->total_amount;
        $totalCost = $tour->services->sum(fn ($s) => (float) $s->cost);

        $entry->update(['entry_date' => $tour->start_date->toDateString()]);

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

        if ($totalCost > 0) {
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

        $this->journalEntryService->updateLines($entry, $lines);
    }
}
