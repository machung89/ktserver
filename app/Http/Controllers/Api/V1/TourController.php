<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Tour;
use App\Models\TourGuideAdvance;
use App\Models\TourPaymentRequest;
use App\Models\TourService;
use App\Services\PaymentService;
use App\Services\TourService as TourServiceClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TourController extends Controller
{
    use ScopedByOrganization;

    public function __construct(
        protected TourServiceClass $tourService,
        protected PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tours = Tour::with(['customer', 'createdBy'])
            ->withSum('services as services_cost_total', 'cost')
            ->withSum('services as services_paid_total', 'paid_amount')
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->search, function ($q, $v) {
                $q->where(function ($q) use ($v) {
                    $q->where('tour_number', 'like', "%{$v}%")
                        ->orWhere('name', 'like', "%{$v}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$v}%"));
                });
            })
            ->when($request->date_from, fn ($q, $v) => $q->whereDate('start_date', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->whereDate('start_date', '<=', $v))
            ->latest()
            ->paginate($request->filled('per_page') ? (int) $request->per_page : 20);

        return response()->json([
            'data' => $tours->map(fn ($t) => $this->formatList($t)),
            'meta' => [
                'current_page' => $tours->currentPage(),
                'per_page' => $tours->perPage(),
                'total' => $tours->total(),
                'last_page' => $tours->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'customer_id' => ['nullable', 'exists:companies,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'num_adults' => ['required', 'integer', 'min:0'],
            'num_children' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'child_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'services' => ['nullable', 'array'],
            'services.*.service_type' => ['required', 'string'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.supplier_id' => ['nullable', 'exists:companies,id'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1'],
            'services.*.days' => ['nullable', 'integer', 'min:1'],
            'services.*.paid_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.notes' => ['nullable', 'string'],
        ]);

        $tour = DB::transaction(function () use ($validated) {
            $numAdults = (int) $validated['num_adults'];
            $numChildren = (int) ($validated['num_children'] ?? 0);
            $adultPrice = (float) $validated['unit_price'];
            $childPrice = (float) ($validated['child_price'] ?? round($adultPrice * 0.7));

            $tour = Tour::create([
                'organization_id' => $this->orgId(),
                'tour_number' => $this->generateTourNumber(),
                'name' => $validated['name'],
                'customer_id' => $validated['customer_id'] ?? null,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'] ?? null,
                'num_adults' => $numAdults,
                'num_children' => $numChildren,
                'num_guests' => $numAdults + $numChildren,
                'unit_price' => $adultPrice,
                'child_price' => $childPrice,
                'total_amount' => $numAdults * $adultPrice + $numChildren * $childPrice,
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            foreach ($validated['services'] ?? [] as $svc) {
                $tour->services()->create($this->buildServiceData($svc));
            }

            return $tour;
        });

        return response()->json(['data' => $this->format($tour->load(['customer', 'createdBy', 'services.supplier']))], 201);
    }

    public function show(Tour $tour): JsonResponse
    {
        $tour->load(['customer', 'createdBy', 'services.supplier',
            'services' => fn ($q) => $q->withSum(['paymentRequests as pending_amount' => fn ($q) => $q->where('status', 'pending')], 'amount'),
        ]);
        $this->loadPaymentHistory($tour);

        return response()->json(['data' => $this->format($tour)]);
    }

    public function update(Request $request, Tour $tour): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'customer_id' => ['nullable', 'exists:companies,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'num_adults' => ['required', 'integer', 'min:0'],
            'num_children' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'child_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'services' => ['nullable', 'array'],
            'services.*.id' => ['nullable', 'exists:tour_services,id'],
            'services.*.service_type' => ['required', 'string'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.supplier_id' => ['nullable', 'exists:companies,id'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1'],
            'services.*.days' => ['nullable', 'integer', 'min:1'],
            'services.*.paid_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.notes' => ['nullable', 'string'],
        ]);

        abort_unless(
            in_array($tour->status, ['draft', 'confirmed', 'completed']),
            422,
            'Không thể sửa tour đã hủy.'
        );

        DB::transaction(function () use ($tour, $validated) {
            $numAdults = (int) $validated['num_adults'];
            $numChildren = (int) ($validated['num_children'] ?? 0);
            $adultPrice = (float) $validated['unit_price'];
            $childPrice = (float) ($validated['child_price'] ?? round($adultPrice * 0.7));

            $tour->update([
                'name' => $validated['name'],
                'customer_id' => $validated['customer_id'] ?? null,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'] ?? null,
                'num_adults' => $numAdults,
                'num_children' => $numChildren,
                'num_guests' => $numAdults + $numChildren,
                'unit_price' => $adultPrice,
                'child_price' => $childPrice,
                'total_amount' => $numAdults * $adultPrice + $numChildren * $childPrice,
                'notes' => $validated['notes'] ?? null,
            ]);

            $incomingIds = collect($validated['services'] ?? [])->pluck('id')->filter()->all();

            // Chỉ xóa dịch vụ chưa thanh toán
            $tour->services()
                ->whereNotIn('id', $incomingIds)
                ->where('paid_amount', 0)
                ->delete();

            foreach ($validated['services'] ?? [] as $svc) {
                if (! empty($svc['id'])) {
                    $existing = TourService::find($svc['id']);
                    if ($existing) {
                        $data = $this->buildServiceData($svc);
                        if ((float) $existing->paid_amount > 0 && (float) $data['cost'] < (float) $existing->paid_amount - 0.01) {
                            throw ValidationException::withMessages([
                                'services' => [sprintf(
                                    'Thành tiền dịch vụ "%s" (%s₫) không được nhỏ hơn số đã thanh toán (%s₫).',
                                    $svc['name'],
                                    number_format($data['cost'], 0, ',', '.'),
                                    number_format($existing->paid_amount, 0, ',', '.')
                                )],
                            ]);
                        }
                        TourService::where('id', $svc['id'])->update($data);
                    }
                } else {
                    $tour->services()->create($this->buildServiceData($svc));
                }
            }

            if (in_array($tour->status, ['confirmed', 'completed'])) {
                $this->tourService->syncJournalEntry($tour);
            }
        });

        return response()->json(['data' => $this->format($tour->load(['customer', 'createdBy', 'services.supplier']))]);
    }

    public function confirm(Tour $tour): JsonResponse
    {
        abort_unless($tour->status === 'draft', 422, 'Chỉ có thể xác nhận tour ở trạng thái nháp.');

        $tour = $this->tourService->confirm($tour);

        return response()->json(['data' => $this->format($tour->load(['customer', 'createdBy', 'services.supplier']))]);
    }

    public function complete(Tour $tour): JsonResponse
    {
        abort_unless($tour->status === 'confirmed', 422, 'Chỉ có thể hoàn thành tour đã xác nhận.');
        $tour->update(['status' => 'completed']);

        return response()->json(['data' => $this->format($tour->load(['customer', 'createdBy', 'services.supplier']))]);
    }

    public function cancel(Tour $tour): JsonResponse
    {
        abort_unless(in_array($tour->status, ['draft', 'confirmed']), 422, 'Không thể hủy tour ở trạng thái này.');
        $tour->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->format($tour->load(['customer', 'createdBy', 'services.supplier']))]);
    }

    public function collect(Request $request, Tour $tour): JsonResponse
    {
        abort_unless(
            in_array($tour->status, ['confirmed', 'completed']),
            422,
            'Chỉ thu tiền tour đã xác nhận.'
        );

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['required', 'exists:accounts,id'],
            'payment_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($tour, $validated) {
            $tourLocked = Tour::lockForUpdate()->find($tour->id);

            $remaining = (float) $tourLocked->total_amount - (float) $tourLocked->paid_amount;
            if ((float) $validated['amount'] > $remaining + 0.01) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf(
                        'Số tiền thu (%s₫) vượt quá số tiền còn lại (%s₫).',
                        number_format($validated['amount'], 0, ',', '.'),
                        number_format($remaining, 0, ',', '.')
                    )],
                ]);
            }

            $this->paymentService->create([
                'organization_id' => $this->orgId(),
                'type' => 'receipt',
                'company_id' => $tourLocked->customer_id,
                'account_id' => $validated['account_id'],
                'payment_date' => $validated['payment_date'],
                'amount' => $validated['amount'],
                'description' => $validated['description'] ?? "Thu tiền tour {$tourLocked->tour_number}",
                'status' => 'approved',
                'reference_type' => Tour::class,
                'reference_id' => $tourLocked->id,
            ]);

            $tourLocked->increment('paid_amount', (float) $validated['amount']);
        });

        $fresh = $tour->fresh(['customer', 'createdBy', 'services.supplier']);
        $this->loadPaymentHistory($fresh);

        return response()->json(['data' => $this->format($fresh)]);
    }

    public function destroy(Tour $tour): JsonResponse
    {
        abort_unless($tour->status === 'draft', 422, 'Chỉ có thể xóa tour ở trạng thái nháp.');
        $tour->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function formatList(Tour $tour): array
    {
        return [
            'id' => $tour->id,
            'tour_number' => $tour->tour_number,
            'name' => $tour->name,
            'customer' => $tour->customer ? ['id' => $tour->customer->id, 'name' => $tour->customer->name] : null,
            'start_date' => $tour->start_date?->toDateString(),
            'end_date' => $tour->end_date?->toDateString(),
            'num_adults' => $tour->num_adults,
            'num_children' => $tour->num_children,
            'num_guests' => $tour->num_guests,
            'unit_price' => $tour->unit_price,
            'child_price' => $tour->child_price,
            'total_amount' => $tour->total_amount,
            'paid_amount' => $tour->paid_amount,
            'receivable' => max(0, (float) $tour->total_amount - (float) $tour->paid_amount),
            'services_cost' => (float) ($tour->services_cost_total ?? $tour->services?->sum(fn ($s) => (float) $s->cost) ?? 0),
            'services_paid' => (float) ($tour->services_paid_total ?? $tour->services?->sum(fn ($s) => (float) $s->paid_amount) ?? 0),
            'payable' => max(0, (float) ($tour->services_cost_total ?? 0) - (float) ($tour->services_paid_total ?? 0)),
            'status' => $tour->status,
            'created_by_name' => $tour->createdBy?->name,
        ];
    }

    private function loadPaymentHistory(Tour $tour): void
    {
        $tour->customerPayments = Payment::with('account')
            ->where('reference_type', Tour::class)
            ->where('reference_id', $tour->id)
            ->where('type', 'receipt')
            ->orderBy('payment_date')
            ->get();

        $tour->servicePayments = TourPaymentRequest::with(['service', 'payment.account'])
            ->where('tour_id', $tour->id)
            ->whereIn('status', ['approved'])
            ->orderBy('approved_at')
            ->get();

        $tour->guideAdvances = TourGuideAdvance::with(['guide', 'account'])
            ->where('tour_id', $tour->id)
            ->orderBy('advance_date')
            ->get();
    }

    /** @return array<string, mixed> */
    private function format(Tour $tour): array
    {
        $servicesCost = $tour->services->sum(fn ($s) => (float) $s->cost);
        $servicesPaid = $tour->services->sum(fn ($s) => (float) $s->paid_amount);

        $tour->services_cost_total = $servicesCost;
        $tour->services_paid_total = $servicesPaid;

        $customerPayments = $tour->customerPayments ?? collect();
        $servicePayments = $tour->servicePayments ?? collect();
        $guideAdvances = $tour->guideAdvances ?? collect();

        return array_merge($this->formatList($tour), [
            'notes' => $tour->notes,
            'customer_payments' => $customerPayments->map(fn ($p) => [
                'id' => $p->id,
                'payment_number' => $p->payment_number,
                'payment_date' => $p->payment_date?->toDateString(),
                'amount' => $p->amount,
                'description' => $p->description,
                'account_name' => $p->account?->name,
            ])->values(),
            'service_payments' => $servicePayments->map(fn ($p) => [
                'id' => $p->id,
                'service_name' => $p->service?->name,
                'amount' => $p->amount,
                'notes' => $p->notes,
                'approved_at' => $p->approved_at?->toDateTimeString(),
                'payment_number' => $p->payment?->payment_number,
                'account_code' => $p->payment?->account?->code,
                'account_name' => $p->payment?->account?->name,
            ])->values(),
            'guide_advances' => $guideAdvances->map(fn ($a) => [
                'id' => $a->id,
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
            ])->values(),
            'services' => $tour->services->map(fn ($s) => [
                'id' => $s->id,
                'service_type' => $s->service_type,
                'name' => $s->name,
                'supplier' => $s->supplier ? [
                    'id' => $s->supplier->id,
                    'name' => $s->supplier->name,
                    'bank_account_name' => $s->supplier->bank_account_name,
                ] : null,
                'supplier_id' => $s->supplier_id,
                'unit_price' => $s->unit_price,
                'quantity' => $s->quantity,
                'days' => $s->days,
                'cost' => $s->cost,
                'paid_amount' => $s->paid_amount,
                'pending_amount' => (float) ($s->pending_amount ?? 0),
                'notes' => $s->notes,
            ])->values(),
        ]);
    }

    /** @param array<string, mixed> $svc
     * @return array<string, mixed>
     */
    private function buildServiceData(array $svc): array
    {
        $unitPrice = (float) ($svc['unit_price'] ?? 0);
        $quantity = (int) ($svc['quantity'] ?? 1);
        $days = (int) ($svc['days'] ?? 1);

        return [
            'service_type' => $svc['service_type'],
            'name' => $svc['name'],
            'supplier_id' => $svc['supplier_id'] ?? null,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'days' => $days,
            'cost' => $unitPrice * $quantity * $days,
            'paid_amount' => (float) ($svc['paid_amount'] ?? 0),
            'notes' => $svc['notes'] ?? null,
        ];
    }

    private function generateTourNumber(): string
    {
        $last = Tour::withoutGlobalScopes()->orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->tour_number, 4)) + 1 : 1;

        return 'TOUR'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
