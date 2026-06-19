<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\Tour;
use App\Models\TourGuideAdvance;
use App\Services\ActivityLogService;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TourGuideAdvanceController extends Controller
{
    use ScopedByOrganization;

    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected ActivityLogService $activityLog,
    ) {}

    public function index(Tour $tour): JsonResponse
    {
        $advances = TourGuideAdvance::with(['guide', 'account'])
            ->where('tour_id', $tour->id)
            ->latest()
            ->get();

        return response()->json(['data' => $advances->map(fn ($a) => $this->format($a))->values()]);
    }

    public function store(Request $request, Tour $tour): JsonResponse
    {
        $validated = $request->validate([
            'guide_id' => ['required', 'exists:users,id'],
            'account_id' => ['required', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'advance_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless($tour->organization_id === $this->orgId(), 403);

        $validated['amount'] = round((float) $validated['amount']); // VND: đồng nguyên

        $advance = DB::transaction(function () use ($tour, $validated) {
            $advance = TourGuideAdvance::create([
                'organization_id' => $this->orgId(),
                'tour_id' => $tour->id,
                'guide_id' => $validated['guide_id'],
                'account_id' => $validated['account_id'],
                'amount' => $validated['amount'],
                'used_amount' => 0,
                'advance_date' => $validated['advance_date'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $guideName = $advance->guide->name;

            $this->journalEntryService->create(
                description: "Tạm ứng HĐV {$guideName} - Tour {$tour->tour_number}",
                entryDate: $validated['advance_date'],
                reference: $advance,
                lines: [
                    [
                        'account_code' => '141',
                        'description' => "Tạm ứng HĐV {$guideName} - {$tour->tour_number}",
                        'debit' => (float) $validated['amount'],
                        'credit' => 0,
                    ],
                    [
                        'account_code' => $advance->account->code,
                        'description' => "Xuất quỹ ứng HĐV {$guideName} - {$tour->tour_number}",
                        'debit' => 0,
                        'credit' => (float) $validated['amount'],
                    ],
                ]
            );

            return $advance;
        });

        $amt = number_format((float) $advance->amount, 0, ',', '.');
        $this->activityLog->log($this->orgId(), Auth::id(), 'advance_created', "Tạm ứng HĐV {$advance->guide?->name} — {$amt}₫", $tour->id);

        return response()->json(['data' => $this->format($advance->load(['guide', 'account']))], 201);
    }

    public function destroy(TourGuideAdvance $tourGuideAdvance): JsonResponse
    {
        abort_unless($tourGuideAdvance->organization_id === $this->orgId(), 403);
        abort_unless((float) $tourGuideAdvance->used_amount === 0.0, 422, 'Không thể xóa tạm ứng đã được sử dụng.');

        $amt = number_format((float) $tourGuideAdvance->amount, 0, ',', '.');
        $guideName = $tourGuideAdvance->guide?->name ?? '';
        $tourId = $tourGuideAdvance->tour_id;

        DB::transaction(function () use ($tourGuideAdvance) {
            JournalEntry::where('reference_type', TourGuideAdvance::class)
                ->where('reference_id', $tourGuideAdvance->id)
                ->each(function ($entry) {
                    $entry->lines()->delete();
                    $entry->delete();
                });

            $tourGuideAdvance->delete();
        });

        $this->activityLog->log($this->orgId(), Auth::id(), 'advance_deleted', "Xóa tạm ứng HĐV {$guideName} — {$amt}₫", $tourId);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function format(TourGuideAdvance $a): array
    {
        return [
            'id' => $a->id,
            'tour_id' => $a->tour_id,
            'guide_id' => $a->guide_id,
            'guide_name' => $a->guide?->name,
            'account_id' => $a->account_id,
            'account_name' => $a->account?->name,
            'account_code' => $a->account?->code,
            'amount' => $a->amount,
            'used_amount' => $a->used_amount,
            'remaining' => max(0, (float) $a->amount - (float) $a->used_amount),
            'advance_date' => $a->advance_date?->toDateString(),
            'notes' => $a->notes,
        ];
    }
}
