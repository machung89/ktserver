<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Tour;
use App\Models\TourGuideAdvance;
use App\Models\TourPaymentRequest;
use App\Models\TourService;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\JournalEntryService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TourPaymentController extends Controller
{
    use ScopedByOrganization;

    public function __construct(
        protected PaymentService $paymentService,
        protected JournalEntryService $journalEntryService,
        protected ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = TourPaymentRequest::with(['tour', 'service', 'supplier', 'requestedBy', 'approvedBy'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->tour_id, fn ($q, $v) => $q->where('tour_id', $v))
            ->when($request->search, function ($q, $v) {
                $q->where(function ($q) use ($v) {
                    $q->where('notes', 'like', "%{$v}%")
                        ->orWhereHas('tour', fn ($tq) => $tq->where('tour_number', 'like', "%{$v}%")->orWhere('name', 'like', "%{$v}%"))
                        ->orWhereHas('service', fn ($sq) => $sq->where('name', 'like', "%{$v}%"))
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$v}%"));
                });
            })
            ->latest()
            ->paginate($request->filled('per_page') ? (int) $request->per_page : 30);

        return response()->json([
            'data' => $items->map(fn ($r) => $this->format($r)),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    /**
     * Sổ thu/chi tour: mọi phiếu thu/chi (Payment) phát sinh từ tour, gồm cả phiếu
     * nháp đang chờ duyệt để duyệt ngay tại bảng (giống trang Thu/chi).
     */
    public function cashflow(Request $request): JsonResponse
    {
        $categoryFor = fn (?string $requestType): string => match ($requestType) {
            'guide_advance' => 'Dự chi HĐV',
            'settlement' => 'QT HĐV - Chi bổ sung',
            'settlement_receipt' => 'QT HĐV - Thu hoàn',
            default => 'Chi dịch vụ NCC',
        };

        $base = Payment::query()
            ->whereIn('reference_type', [Tour::class, TourPaymentRequest::class])
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->where('payment_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('payment_date', '<=', $v))
            ->when($request->search, function ($q, $v) {
                $q->where(function ($q) use ($v) {
                    $q->where('payment_number', 'like', "%{$v}%")
                        ->orWhere('description', 'like', "%{$v}%")
                        ->orWhereHasMorph('reference', [Tour::class], fn ($tq) => $tq->where('tour_number', 'like', "%{$v}%")->orWhere('name', 'like', "%{$v}%"))
                        ->orWhereHasMorph('reference', [TourPaymentRequest::class], fn ($rq) => $rq->whereHas('tour', fn ($tq) => $tq->where('tour_number', 'like', "%{$v}%")->orWhere('name', 'like', "%{$v}%")));
                });
            });

        // Tổng tiền thật đã ghi nhận (phiếu đã duyệt); phiếu nháp chưa tính.
        $totalIn = (float) (clone $base)->where('type', 'receipt')->where('status', 'approved')->sum('amount');
        $totalOut = (float) (clone $base)->where('type', 'payment')->where('status', 'approved')->sum('amount');

        $payments = $base
            ->with(['account', 'company.bank'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate($request->filled('per_page') ? (int) $request->per_page : 30);

        $tourIds = $payments->where('reference_type', Tour::class)->pluck('reference_id')->unique();
        $tprIds = $payments->where('reference_type', TourPaymentRequest::class)->pluck('reference_id')->unique();
        $tours = Tour::whereIn('id', $tourIds)->get(['id', 'tour_number', 'name'])->keyBy('id');
        $tprs = TourPaymentRequest::with('tour:id,tour_number,name')->whereIn('id', $tprIds)->get()->keyBy('id');

        return response()->json([
            'data' => $payments->map(function ($p) use ($tours, $tprs, $categoryFor) {
                $tour = null;
                $category = 'Khác';
                if ($p->reference_type === Tour::class) {
                    $tour = $tours->get($p->reference_id);
                    $category = 'Thu tiền khách';
                } elseif ($p->reference_type === TourPaymentRequest::class) {
                    $tpr = $tprs->get($p->reference_id);
                    $tour = $tpr?->tour;
                    $category = $categoryFor($tpr?->request_type);
                }

                $company = $p->company;

                return [
                    'id' => $p->id,
                    'status' => $p->status,
                    'payment_number' => $p->payment_number,
                    'payment_date' => $p->payment_date?->toDateString(),
                    'type' => $p->type,
                    'category' => $category,
                    'amount' => (float) $p->amount,
                    'account_code' => $p->account?->code,
                    'account_name' => $p->account?->name,
                    'company_name' => $company?->name,
                    'company' => $company ? [
                        'id' => $company->id,
                        'name' => $company->name,
                        'bank_account_number' => $company->bank_account_number,
                        'bank_account_name' => $company->bank_account_name,
                        'bank' => $company->bank ? [
                            'bin' => $company->bank->bin,
                            'name' => $company->bank->name,
                            'short_name' => $company->bank->short_name,
                            'logo' => $company->bank->logo,
                        ] : null,
                    ] : null,
                    'description' => $p->description,
                    'tour_id' => $tour?->id,
                    'tour_number' => $tour?->tour_number,
                    'tour_name' => $tour?->name,
                ];
            }),
            'summary' => [
                'total_in' => $totalIn,
                'total_out' => $totalOut,
                'net' => $totalIn - $totalOut,
            ],
            'meta' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tour_service_id' => ['required', 'exists:tour_services,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $service = TourService::with('tour')->findOrFail($validated['tour_service_id']);
        abort_unless($service->tour->organization_id === $this->orgId(), 403);

        $pendingLocked = TourPaymentRequest::where('tour_service_id', $service->id)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->sum('amount');

        $remaining = (float) $service->cost - (float) $service->paid_amount - (float) $pendingLocked;
        if ((float) $validated['amount'] > $remaining + 0.01) {
            throw ValidationException::withMessages([
                'amount' => [sprintf(
                    'Số tiền (%s₫) vượt quá số còn có thể lên lệnh (%s₫).',
                    number_format($validated['amount'], 0, ',', '.'),
                    number_format(max(0, $remaining), 0, ',', '.')
                )],
            ]);
        }

        $item = TourPaymentRequest::create([
            'organization_id' => $this->orgId(),
            'tour_id' => $service->tour_id,
            'tour_service_id' => $service->id,
            'supplier_id' => $service->supplier_id,
            'amount' => $validated['amount'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
            'requested_by' => Auth::id(),
        ]);

        $amt = number_format((float) $item->amount, 0, ',', '.');
        $svcName = $item->service?->name ?? $item->notes ?? '';
        $this->activityLog->log($this->orgId(), Auth::id(), 'payment_created', "Lên lệnh thanh toán: {$svcName} — {$amt}₫", $item->tour_id);

        return response()->json(['data' => $this->format($item->load(['tour', 'service', 'supplier', 'requestedBy']))], 201);
    }

    public function approve(Request $request, TourPaymentRequest $tourPayment): JsonResponse
    {
        abort_unless($tourPayment->status === 'pending', 422, 'Chỉ có thể duyệt phiếu đang chờ.');

        $useSupplierAdvance = $request->boolean('use_advance');
        $guideAdvanceId = $request->input('guide_advance_id');

        if ($useSupplierAdvance) {
            $available = $this->paymentService->availableSupplierAdvance(
                (int) $tourPayment->supplier_id,
                $this->orgId()
            );

            if ((float) $tourPayment->amount > $available + 0.01) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf(
                        'Số tiền áp dụng (%s₫) vượt quá số dư trả trước còn lại (%s₫).',
                        number_format($tourPayment->amount, 0, ',', '.'),
                        number_format($available, 0, ',', '.')
                    )],
                ]);
            }
        }

        if ($guideAdvanceId) {
            // Kiểm tra sơ bộ quyền sở hữu trước khi vào transaction
            $guideAdvanceCheck = TourGuideAdvance::findOrFail($guideAdvanceId);
            abort_unless($guideAdvanceCheck->organization_id === $this->orgId(), 403);
        }

        DB::transaction(function () use (&$tourPayment, $useSupplierAdvance, $guideAdvanceId) {
            // Khóa phiếu + kiểm tra lại trạng thái để tránh duyệt 2 lần (sinh phiếu chi & bút toán trùng)
            $tourPayment = TourPaymentRequest::lockForUpdate()->findOrFail($tourPayment->id);
            if ($tourPayment->status !== 'pending') {
                throw ValidationException::withMessages(['status' => ['Phiếu đã được duyệt hoặc xử lý bởi người khác.']]);
            }

            $tourPayment->update([
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            $desc = $tourPayment->notes
                ?: "Chi dịch vụ {$tourPayment->service?->name} - Tour {$tourPayment->tour?->tour_number}";

            if ($guideAdvanceId) {
                // Dùng tạm ứng HĐV: Dr 331 / Cr 141
                // Lock trước khi kiểm tra số dư để tránh race condition
                $guideAdvance = TourGuideAdvance::lockForUpdate()->find($guideAdvanceId);

                $remaining = (float) $guideAdvance->amount - (float) $guideAdvance->used_amount;
                if ((float) $tourPayment->amount > $remaining + 0.01) {
                    throw ValidationException::withMessages([
                        'amount' => [sprintf(
                            'Số tiền áp dụng (%s₫) vượt quá tạm ứng HĐV còn lại (%s₫).',
                            number_format($tourPayment->amount, 0, ',', '.'),
                            number_format(max(0, $remaining), 0, ',', '.')
                        )],
                    ]);
                }

                $payment = Payment::create([
                    'payment_number' => 'PC'.str_pad(
                        Payment::where('type', 'payment')->withoutGlobalScopes()->max('id') + 1,
                        6, '0', STR_PAD_LEFT
                    ),
                    'organization_id' => $this->orgId(),
                    'type' => 'payment',
                    'company_id' => $tourPayment->supplier_id,
                    'account_id' => null,
                    'payment_date' => now()->toDateString(),
                    'amount' => $tourPayment->amount,
                    'description' => $desc,
                    'is_advance' => false,
                    'reference_type' => TourPaymentRequest::class,
                    'reference_id' => $tourPayment->id,
                    'status' => 'approved',
                ]);

                $this->journalEntryService->create(
                    description: $desc,
                    entryDate: now()->toDateString(),
                    reference: $payment,
                    lines: [
                        [
                            'account_code' => '331',
                            'description' => $desc,
                            'debit' => (float) $tourPayment->amount,
                            'credit' => 0,
                        ],
                        [
                            'account_code' => '141',
                            'description' => "Thanh toán từ tạm ứng HĐV {$guideAdvance->guide?->name}",
                            'debit' => 0,
                            'credit' => (float) $tourPayment->amount,
                        ],
                    ]
                );

                $guideAdvance->increment('used_amount', (float) $tourPayment->amount);

                if ($tourPayment->service) {
                    $tourPayment->service->increment('paid_amount', (float) $tourPayment->amount);
                }
            } elseif ($useSupplierAdvance) {
                // Áp từ trả trước NCC — không sinh bút toán mới
                $payment = Payment::create([
                    'payment_number' => 'PC'.str_pad(
                        Payment::where('type', 'payment')->withoutGlobalScopes()->max('id') + 1,
                        6, '0', STR_PAD_LEFT
                    ),
                    'organization_id' => $this->orgId(),
                    'type' => 'payment',
                    'company_id' => $tourPayment->supplier_id,
                    'account_id' => null,
                    'payment_date' => now()->toDateString(),
                    'amount' => $tourPayment->amount,
                    'description' => $desc,
                    'is_advance' => true,
                    'reference_type' => TourPaymentRequest::class,
                    'reference_id' => $tourPayment->id,
                    'status' => 'approved',
                ]);

                if ($tourPayment->service) {
                    $tourPayment->service->increment('paid_amount', (float) $tourPayment->amount);
                }
            } elseif ($tourPayment->request_type === 'settlement_receipt') {
                // HĐV thừa tiền → phiếu thu hoàn lại
                $payment = $this->paymentService->createDraft([
                    'organization_id' => $this->orgId(),
                    'type' => 'receipt',
                    'company_id' => $tourPayment->supplier_id,
                    'account_id' => null,
                    'payment_date' => now()->toDateString(),
                    'amount' => $tourPayment->amount,
                    'description' => $desc,
                    'reference_type' => TourPaymentRequest::class,
                    'reference_id' => $tourPayment->id,
                ]);
            } else {
                $payment = $this->paymentService->createDraft([
                    'organization_id' => $this->orgId(),
                    'type' => 'payment',
                    'company_id' => $tourPayment->supplier_id,
                    'account_id' => null,
                    'payment_date' => now()->toDateString(),
                    'amount' => $tourPayment->amount,
                    'description' => $desc,
                    'reference_type' => TourPaymentRequest::class,
                    'reference_id' => $tourPayment->id,
                ]);
            }

            $tourPayment->update(['payment_id' => $payment->id]);

            if (in_array($tourPayment->request_type, ['settlement', 'settlement_receipt'])) {
                // Chỉ cộng paid_amount cho operating services (settlement services đã được set paid_amount = guide_paid_amount khi lưu quyết toán)
                TourService::where('tour_id', $tourPayment->tour_id)
                    ->where('service_stage', 'operating')
                    ->where('guide_paid_amount', '>', 0)
                    ->each(fn ($s) => $s->increment('paid_amount', (float) $s->guide_paid_amount));
            }

            // Bump version để form tour đang mở (báo giá/điều hành/quyết toán) không ghi đè paid_amount vừa thay đổi
            Tour::where('id', $tourPayment->tour_id)->increment('version');
        });

        $amt = number_format((float) $tourPayment->amount, 0, ',', '.');
        $svcName = $tourPayment->service?->name ?? $tourPayment->notes ?? '';
        $this->activityLog->log($this->orgId(), Auth::id(), 'payment_approved', "Duyệt lệnh thanh toán: {$svcName} — {$amt}₫", $tourPayment->tour_id);

        return response()->json(['data' => $this->format($tourPayment->load(['tour', 'service', 'supplier', 'requestedBy', 'approvedBy']))]);
    }

    public function supplierAdvanceBalance(Request $request): JsonResponse
    {
        $request->validate(['company_id' => ['required', 'exists:companies,id']]);

        $balance = $this->paymentService->availableSupplierAdvance(
            (int) $request->company_id,
            $this->orgId()
        );

        return response()->json(['balance' => $balance]);
    }

    public function reject(Request $request, TourPaymentRequest $tourPayment): JsonResponse
    {
        abort_unless($tourPayment->status === 'pending', 422, 'Chỉ có thể từ chối phiếu đang chờ.');

        $validated = $request->validate([
            'reject_reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use (&$tourPayment, $validated) {
            // Khóa phiếu + kiểm tra lại trạng thái để tránh từ chối 2 lần
            $tourPayment = TourPaymentRequest::lockForUpdate()->findOrFail($tourPayment->id);
            if ($tourPayment->status !== 'pending') {
                throw ValidationException::withMessages(['status' => ['Phiếu đã được xử lý bởi người khác.']]);
            }

            if (in_array($tourPayment->request_type, ['settlement', 'settlement_receipt'])) {
                TourService::where('tour_id', $tourPayment->tour_id)
                    ->where('service_stage', 'settlement')
                    ->update(['paid_amount' => 0]);
                $tourPayment->tour?->update(['stage' => 'operating']);
            } elseif ($tourPayment->request_type === 'guide_advance') {
                $tourPayment->tour?->update(['stage' => 'quote']);
            }

            $tourPayment->update([
                'status' => 'rejected',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'reject_reason' => $validated['reject_reason'] ?? null,
            ]);

            Tour::where('id', $tourPayment->tour_id)->increment('version');
        });

        $amt = number_format((float) $tourPayment->amount, 0, ',', '.');
        $svcName = $tourPayment->service?->name ?? $tourPayment->notes ?? '';
        $this->activityLog->log($this->orgId(), Auth::id(), 'payment_rejected', "Từ chối lệnh thanh toán: {$svcName} — {$amt}₫", $tourPayment->tour_id);

        return response()->json(['data' => $this->format($tourPayment->load(['tour', 'service', 'supplier', 'requestedBy', 'approvedBy']))]);
    }

    public function destroy(TourPaymentRequest $tourPayment): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        // Lệnh đã duyệt không được phép xóa (phải dùng nghiệp vụ hoàn/điều chỉnh riêng).
        abort_if($tourPayment->status === 'approved', 422, 'Lệnh thanh toán đã duyệt không được phép xóa.');

        // Người duyệt được xóa lệnh chưa duyệt; người lập lệnh chỉ được tự hủy lệnh pending của mình.
        $canApprove = $user->hasPermission('tours.payment_approve');
        $isOwnPending = $tourPayment->status === 'pending'
            && (int) $tourPayment->requested_by === (int) $user->id
            && $user->hasPermission('tours.payment_request');

        if (! $canApprove && ! $isOwnPending) {
            return response()->json(['message' => 'Bạn không có quyền xóa lệnh thanh toán này.'], 403);
        }

        $amt = number_format((float) $tourPayment->amount, 0, ',', '.');
        $svcName = $tourPayment->service?->name ?? $tourPayment->notes ?? '';
        $tourId = $tourPayment->tour_id;

        DB::transaction(function () use ($tourPayment) {
            $payment = $tourPayment->payment_id ? Payment::find($tourPayment->payment_id) : null;

            $wasActuallyPaid = $payment && $payment->status === 'approved';

            // Hoàn paid_amount dịch vụ thường
            if ($wasActuallyPaid && $tourPayment->service) {
                $tourPayment->service->decrement('paid_amount', (float) $tourPayment->amount);
            }

            // Xử lý theo loại phiếu
            if (in_array($tourPayment->request_type, ['settlement', 'settlement_receipt'])) {
                // Hoàn paid_amount operating services nếu đã được duyệt
                if ($wasActuallyPaid) {
                    TourService::where('tour_id', $tourPayment->tour_id)
                        ->where('service_stage', 'operating')
                        ->where('guide_paid_amount', '>', 0)
                        ->each(fn ($s) => $s->decrement('paid_amount', (float) $s->guide_paid_amount));
                }
                // Hoàn paid_amount settlement services (được set ngay khi lưu quyết toán)
                TourService::where('tour_id', $tourPayment->tour_id)
                    ->where('service_stage', 'settlement')
                    ->update(['paid_amount' => 0]);
                // Revert tour stage về điều hành
                $tourPayment->tour?->update(['stage' => 'operating']);
            } elseif ($tourPayment->request_type === 'guide_advance') {
                // Revert tour stage về báo giá
                $tourPayment->tour?->update(['stage' => 'quote']);
            }

            // Xóa phiếu chi/thu + bút toán liên quan
            if ($payment) {
                JournalEntry::where('reference_type', Payment::class)
                    ->where('reference_id', $payment->id)
                    ->each(function ($entry) {
                        $entry->lines()->delete();
                        $entry->delete();
                    });
                $payment->delete();
            }

            Tour::where('id', $tourPayment->tour_id)->increment('version');

            $tourPayment->delete();
        });

        $this->activityLog->log($this->orgId(), Auth::id(), 'payment_deleted', "Xóa lệnh thanh toán: {$svcName} — {$amt}₫", $tourId);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function format(TourPaymentRequest $r): array
    {
        return [
            'id' => $r->id,
            'tour_id' => $r->tour_id,
            'tour_number' => $r->tour?->tour_number,
            'tour_name' => $r->tour?->name,
            'request_type' => $r->request_type ?? 'service',
            'service_id' => $r->tour_service_id,
            'service_type' => $r->service?->service_type,
            'service_name' => $r->service?->name ?? $r->notes,
            'supplier' => $r->supplier ? ['id' => $r->supplier->id, 'name' => $r->supplier->name] : null,
            'amount' => $r->amount,
            'notes' => $r->notes,
            'status' => $r->status,
            'reject_reason' => $r->reject_reason,
            'requested_by' => $r->requested_by,
            'requested_by_name' => $r->requestedBy?->name,
            'approved_by_name' => $r->approvedBy?->name,
            'approved_at' => $r->approved_at?->toDateTimeString(),
            'created_at' => $r->created_at?->toDateTimeString(),
            'payment_id' => $r->payment_id,
        ];
    }
}
