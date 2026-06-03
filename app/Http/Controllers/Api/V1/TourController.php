<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Tour;
use App\Models\TourExtraRevenue;
use App\Models\TourGuideAdvance;
use App\Models\TourPaymentRequest;
use App\Models\TourService;
use App\Models\User;
use App\Services\ActivityLogService;
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
        protected ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $canViewAll = $user->hasPermission('tours.view_all');
        $createdByFilter = $canViewAll ? null : array_merge([Auth::id()], $user->getViewableUserIds());

        $base = Tour::query()
            ->withSum(['services as services_cost_total' => fn ($q) => $q->where('service_stage', 'operating')], 'cost')
            ->withSum(['services as services_paid_total' => fn ($q) => $q->whereIn('service_stage', ['operating', 'settlement'])], 'paid_amount')
            ->when($createdByFilter, fn ($q) => $q->where(function ($sub) use ($createdByFilter) {
                // Xem tour của mình (hoặc người được phép xem) HOẶC tour mình điều hành
                $sub->whereIn('created_by', $createdByFilter)
                    ->orWhere('operated_by', Auth::id());
            }))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->stage, fn ($q, $v) => $q->where('stage', $v))
            ->when($request->search, function ($q, $v) {
                $q->where(function ($q) use ($v) {
                    $q->where('tour_number', 'like', "%{$v}%")
                        ->orWhere('name', 'like', "%{$v}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$v}%"));
                });
            })
            ->when($request->date_from, fn ($q, $v) => $q->where('start_date', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->where('start_date', '<=', $v));

        // Tổng phải thu / phải trả trên toàn bộ kết quả lọc (không chỉ trang hiện tại); bỏ qua tour đã hủy
        $summaryRows = (clone $base)->where('status', '!=', 'cancelled')->get();
        $totalReceivable = $summaryRows->sum(fn ($t) => max(0, (float) $t->total_amount - (float) $t->paid_amount));
        $totalPayable = $summaryRows->sum(fn ($t) => max(0, (float) ($t->services_cost_total ?? 0) - (float) ($t->services_paid_total ?? 0)));

        $tours = $base
            ->with(['customer', 'createdBy', 'operatedBy'])
            ->orderByDesc('is_featured') // Tour nổi bật lên đầu
            ->latest()
            ->paginate($request->filled('per_page') ? (int) $request->per_page : 20);

        return response()->json([
            'data' => $tours->map(fn ($t) => $this->formatList($t)),
            'summary' => [
                'total_receivable' => $totalReceivable,
                'total_payable' => $totalPayable,
            ],
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
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'services' => ['nullable', 'array'],
            'services.*.service_type' => ['required', 'string'],
            'services.*.service_stage' => ['nullable', 'string'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.supplier_id' => ['nullable', 'exists:companies,id'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1'],
            'services.*.days' => ['nullable', 'integer', 'min:1'],
            'services.*.paid_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.advance_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.guide_paid_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'services.*.notes' => ['nullable', 'string'],
        ]);

        $tour = DB::transaction(function () use ($validated) {
            $numAdults = (int) $validated['num_adults'];
            $numChildren = (int) ($validated['num_children'] ?? 0);
            $adultPrice = (float) $validated['unit_price'];
            $childPrice = (float) ($validated['child_price'] ?? round($adultPrice * 0.7));
            $vatRate = (float) ($validated['vat_rate'] ?? 0);
            $subtotal = $numAdults * $adultPrice + $numChildren * $childPrice;
            $taxAmount = round($subtotal * $vatRate / 100, 2);

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
                'vat_rate' => $vatRate,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $subtotal + $taxAmount,
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            foreach ($validated['services'] ?? [] as $svc) {
                $tour->services()->create($this->buildServiceData($svc));
            }

            return $tour;
        });

        $tour->load(['customer', 'createdBy', 'operatedBy', 'services.supplier']);
        $this->loadPaymentHistory($tour);

        $this->activityLog->log($this->orgId(), Auth::id(), 'created', "Tạo tour #{$tour->tour_number}: {$tour->name}", $tour->id);

        return response()->json(['data' => $this->format($tour)], 201);
    }

    public function show(Tour $tour): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        if (! $user->hasPermission('tours.view_all')) {
            $viewableIds = array_merge([Auth::id()], $user->getViewableUserIds());
            $canView = in_array($tour->created_by, $viewableIds) || $tour->operated_by === Auth::id();
            abort_unless($canView, 403, 'Bạn không có quyền xem tour này.');
        }

        $tour->load(['customer', 'createdBy', 'operatedBy', 'services.supplier', 'extraRevenues',
            'services' => fn ($q) => $q->withSum(['paymentRequests as pending_amount' => fn ($q) => $q->where('status', 'pending')], 'amount'),
        ]);
        $this->loadPaymentHistory($tour);

        return response()->json(['data' => $this->format($tour)]);
    }

    /**
     * Optimistic lock: chặn ghi đè nếu tour đã bị người khác sửa kể từ lúc client tải dữ liệu.
     */
    private function assertVersionMatch(Tour $tour, array $validated): void
    {
        if (isset($validated['version']) && (int) $tour->version !== (int) $validated['version']) {
            abort(409, 'Tour đã được người khác cập nhật. Vui lòng tải lại để xem thay đổi mới nhất.');
        }
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
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'version' => ['nullable', 'integer'],
            'services' => ['nullable', 'array'],
            'services.*.id' => ['nullable', 'exists:tour_services,id'],
            'services.*.service_type' => ['required', 'string'],
            'services.*.service_stage' => ['nullable', 'string'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.supplier_id' => ['nullable', 'exists:companies,id'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1'],
            'services.*.days' => ['nullable', 'integer', 'min:1'],
            'services.*.paid_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.advance_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.guide_paid_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'services.*.notes' => ['nullable', 'string'],
        ]);

        abort_unless(
            $tour->status === 'draft',
            422,
            'Chỉ có thể sửa báo giá khi tour ở trạng thái nháp (chưa xác nhận).'
        );

        DB::transaction(function () use (&$tour, $validated) {
            // Khóa bản ghi tour để tránh 2 người sửa đè lên nhau
            $tour = Tour::lockForUpdate()->findOrFail($tour->id);
            if ($tour->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Chỉ có thể sửa báo giá khi tour ở trạng thái nháp (chưa xác nhận).']]);
            }
            $this->assertVersionMatch($tour, $validated);

            $numAdults = (int) $validated['num_adults'];
            $numChildren = (int) ($validated['num_children'] ?? 0);
            $adultPrice = (float) $validated['unit_price'];
            $childPrice = (float) ($validated['child_price'] ?? round($adultPrice * 0.7));
            $vatRate = (float) ($validated['vat_rate'] ?? 0);
            $subtotal = $numAdults * $adultPrice + $numChildren * $childPrice;
            $taxAmount = round($subtotal * $vatRate / 100, 2);

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
                'vat_rate' => $vatRate,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $subtotal + $taxAmount + (float) $tour->extra_revenues_total,
                'notes' => $validated['notes'] ?? null,
                'version' => $tour->version + 1,
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

        $tour->load(['customer', 'createdBy', 'operatedBy', 'services.supplier']);
        $this->loadPaymentHistory($tour);

        $this->activityLog->log($this->orgId(), Auth::id(), 'updated', "Cập nhật báo giá tour #{$tour->tour_number}", $tour->id);

        return response()->json(['data' => $this->format($tour)]);
    }

    public function operate(Request $request, Tour $tour): JsonResponse
    {
        abort_if($tour->status === 'completed', 422, 'Tour đã hoàn thành, chỉ được thực hiện thanh toán.');
        abort_unless(in_array($tour->stage, ['quote', 'operating']), 422, 'Tour không ở giai đoạn có thể điều hành.');

        $validated = $request->validate([
            'services' => ['nullable', 'array'],
            'services.*.id' => ['nullable', 'exists:tour_services,id'],
            'services.*.service_type' => ['required', 'string'],
            'services.*.service_stage' => ['nullable', 'string'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.supplier_id' => ['nullable', 'exists:companies,id'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1'],
            'services.*.days' => ['nullable', 'integer', 'min:1'],
            'services.*.advance_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.notes' => ['nullable', 'string'],
            'version' => ['nullable', 'integer'],
            'extra_revenues' => ['nullable', 'array'],
            'extra_revenues.*.id' => ['nullable', 'exists:tour_extra_revenues,id'],
            'extra_revenues.*.name' => ['required', 'string', 'max:255'],
            'extra_revenues.*.quantity' => ['nullable', 'integer', 'min:1'],
            'extra_revenues.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'extra_revenues.*.notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use (&$tour, $validated) {
            // Khóa tour để tránh điều hành chồng chéo
            $tour = Tour::lockForUpdate()->findOrFail($tour->id);
            if (! in_array($tour->stage, ['quote', 'operating'])) {
                throw ValidationException::withMessages(['stage' => ['Tour không ở giai đoạn có thể điều hành.']]);
            }
            $this->assertVersionMatch($tour, $validated);

            $incomingIds = collect($validated['services'] ?? [])->pluck('id')->filter()->all();

            $hasApprovedAdvance = $tour->paymentRequests()
                ->where('request_type', 'guide_advance')
                ->where('status', 'approved')
                ->exists();

            $tour->services()
                ->whereNotIn('id', $incomingIds)
                ->where('paid_amount', 0)
                ->where('service_stage', 'quote')
                ->delete();

            foreach ($validated['services'] ?? [] as $svc) {
                if (! empty($svc['id'])) {
                    if (($svc['service_stage'] ?? 'quote') === 'quote') {
                        $updateData = ['supplier_id' => $svc['supplier_id'] ?? null];
                        if (! $hasApprovedAdvance) {
                            $updateData['advance_amount'] = (float) ($svc['advance_amount'] ?? 0);
                        }
                        TourService::where('id', $svc['id'])->update($updateData);
                    } else {
                        $data = $this->buildServiceData($svc);
                        if ($hasApprovedAdvance) {
                            unset($data['advance_amount']);
                        }
                        TourService::where('id', $svc['id'])->update($data);
                    }
                } else {
                    $newData = array_merge(
                        $this->buildServiceData($svc),
                        ['service_stage' => 'operating']
                    );
                    if ($hasApprovedAdvance) {
                        $newData['advance_amount'] = 0;
                    }
                    $tour->services()->create($newData);
                }
            }

            $this->syncExtraRevenues($tour, $validated['extra_revenues'] ?? []);

            // Người lưu điều hành lần đầu được gắn là nhân viên điều hành tour
            $operatorUpdate = ['stage' => 'operating', 'version' => $tour->version + 1];
            if (! $tour->operated_by) {
                $operatorUpdate['operated_by'] = Auth::id();
            }
            $tour->update($operatorUpdate);

            if (! $hasApprovedAdvance) {
                $totalAdvance = $tour->services()->where('service_stage', 'operating')->sum('advance_amount');
                $tour->paymentRequests()
                    ->where('request_type', 'guide_advance')
                    ->where('status', 'pending')
                    ->delete();

                if ($totalAdvance > 0.01) {
                    TourPaymentRequest::create([
                        'organization_id' => $tour->organization_id,
                        'tour_id' => $tour->id,
                        'tour_service_id' => null,
                        'request_type' => 'guide_advance',
                        'amount' => $totalAdvance,
                        'status' => 'pending',
                        'notes' => "Dự chi HĐV tour {$tour->tour_number}",
                        'requested_by' => Auth::id(),
                    ]);
                }
            }
        });

        $fresh = $tour->fresh(['customer', 'createdBy', 'operatedBy', 'services.supplier', 'extraRevenues']);
        $this->loadPaymentHistory($fresh);

        $this->activityLog->log($this->orgId(), Auth::id(), 'operated', "Lưu điều hành tour #{$tour->tour_number}", $tour->id);

        return response()->json(['data' => $this->format($fresh)]);
    }

    public function settle(Request $request, Tour $tour): JsonResponse
    {
        abort_if($tour->status === 'completed', 422, 'Tour đã hoàn thành, chỉ được thực hiện thanh toán.');
        abort_unless(in_array($tour->stage, ['operating', 'settling']), 422, 'Tour chưa qua giai đoạn điều hành.');

        $validated = $request->validate([
            'services' => ['nullable', 'array'],
            'services.*.id' => ['nullable', 'exists:tour_services,id'],
            'services.*.service_type' => ['required', 'string'],
            'services.*.service_stage' => ['nullable', 'string'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.supplier_id' => ['nullable', 'exists:companies,id'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1'],
            'services.*.days' => ['nullable', 'integer', 'min:1'],
            'services.*.guide_paid_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'services.*.notes' => ['nullable', 'string'],
            'version' => ['nullable', 'integer'],
            'extra_revenues' => ['nullable', 'array'],
            'extra_revenues.*.id' => ['nullable', 'exists:tour_extra_revenues,id'],
            'extra_revenues.*.name' => ['required', 'string', 'max:255'],
            'extra_revenues.*.quantity' => ['nullable', 'integer', 'min:1'],
            'extra_revenues.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'extra_revenues.*.notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use (&$tour, $validated) {
            // Khóa tour để tránh quyết toán chồng chéo
            $tour = Tour::lockForUpdate()->findOrFail($tour->id);
            if (! in_array($tour->stage, ['operating', 'settling'])) {
                throw ValidationException::withMessages(['stage' => ['Tour chưa qua giai đoạn điều hành.']]);
            }
            $this->assertVersionMatch($tour, $validated);

            $incomingIds = collect($validated['services'] ?? [])->pluck('id')->filter()->all();

            $tour->services()
                ->whereNotIn('id', $incomingIds)
                ->where('paid_amount', 0)
                ->where('service_stage', 'settlement')
                ->delete();

            foreach ($validated['services'] ?? [] as $svc) {
                $guidePaid = (float) ($svc['guide_paid_amount'] ?? 0);
                $isSettlement = ($svc['service_stage'] ?? '') === 'settlement';

                if (! empty($svc['id'])) {
                    $updateData = ['guide_paid_amount' => $guidePaid];
                    if ($isSettlement) {
                        $updateData['paid_amount'] = $guidePaid;
                    }
                    TourService::where('id', $svc['id'])->update($updateData);
                } else {
                    $data = array_merge(
                        $this->buildServiceData($svc),
                        ['service_stage' => 'settlement']
                    );
                    // Dịch vụ phát sinh: guide đã trả trực tiếp
                    $data['paid_amount'] = $guidePaid;
                    $tour->services()->create($data);
                }
            }

            $this->syncExtraRevenues($tour, $validated['extra_revenues'] ?? []);

            $tour->update(['stage' => 'settling', 'version' => $tour->version + 1]);

            $totalAdvance = $tour->services()->where('service_stage', 'operating')->sum('advance_amount');
            $totalGuidePaid = $tour->services()->sum('guide_paid_amount');
            $net = (float) $totalGuidePaid - (float) $totalAdvance;

            $tour->paymentRequests()
                ->whereIn('request_type', ['settlement', 'settlement_receipt'])
                ->where('status', 'pending')
                ->delete();

            if (abs($net) > 0.01) {
                // net > 0: guide chi nhiều hơn tạm ứng → phiếu chi bổ sung
                // net < 0: guide còn thừa tiền → phiếu thu hoàn lại
                TourPaymentRequest::create([
                    'organization_id' => $tour->organization_id,
                    'tour_id' => $tour->id,
                    'tour_service_id' => null,
                    'request_type' => $net > 0 ? 'settlement' : 'settlement_receipt',
                    'amount' => abs($net),
                    'status' => 'pending',
                    'notes' => $net > 0
                        ? "Quyết toán HĐV tour {$tour->tour_number} (chi bổ sung)"
                        : "Quyết toán HĐV tour {$tour->tour_number} (thu hoàn thừa)",
                    'requested_by' => Auth::id(),
                ]);
            }
        });

        $fresh = $tour->fresh(['customer', 'createdBy', 'operatedBy', 'services.supplier', 'extraRevenues']);
        $this->loadPaymentHistory($fresh);

        $this->activityLog->log($this->orgId(), Auth::id(), 'settled', "Lưu quyết toán tour #{$tour->tour_number}", $tour->id);

        return response()->json(['data' => $this->format($fresh)]);
    }

    public function confirm(Tour $tour): JsonResponse
    {
        abort_unless($tour->status === 'draft', 422, 'Chỉ có thể xác nhận tour ở trạng thái nháp.');

        $tour = $this->tourService->confirm($tour);

        $tour->load(['customer', 'createdBy', 'operatedBy', 'services.supplier']);
        $this->loadPaymentHistory($tour);

        $this->activityLog->log($this->orgId(), Auth::id(), 'confirmed', "Xác nhận tour #{$tour->tour_number}", $tour->id);

        return response()->json(['data' => $this->format($tour)]);
    }

    public function complete(Tour $tour): JsonResponse
    {
        abort_unless($tour->status === 'confirmed', 422, 'Chỉ có thể hoàn thành tour đã xác nhận.');
        $tour->update(['status' => 'completed']);

        $tour->load(['customer', 'createdBy', 'operatedBy', 'services.supplier']);
        $this->loadPaymentHistory($tour);

        $this->activityLog->log($this->orgId(), Auth::id(), 'completed', "Hoàn thành tour #{$tour->tour_number}", $tour->id);

        return response()->json(['data' => $this->format($tour)]);
    }

    /**
     * Đổi trạng thái tour thủ công (quyền tours.change_status).
     * Chỉ cập nhật cột status — không chạy lại bút toán/đặt chỗ; dùng để điều chỉnh đặc biệt.
     */
    public function changeStatus(Request $request, Tour $tour): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:draft,confirmed,completed,cancelled'],
        ]);

        $old = $tour->status;
        $new = $validated['status'];

        if ($old === $new) {
            return response()->json(['data' => $this->format($tour->load(['customer', 'createdBy', 'operatedBy', 'services.supplier']))]);
        }

        DB::transaction(function () use (&$tour, $new) {
            $tour = Tour::lockForUpdate()->findOrFail($tour->id);
            $tour->update(['status' => $new, 'version' => $tour->version + 1]);
        });

        $tour->load(['customer', 'createdBy', 'operatedBy', 'services.supplier']);
        $this->loadPaymentHistory($tour);

        $this->activityLog->log(
            $this->orgId(), Auth::id(), 'status_changed',
            "Đổi trạng thái tour #{$tour->tour_number}: {$old} → {$new}", $tour->id
        );

        return response()->json(['data' => $this->format($tour)]);
    }

    public function cancel(Tour $tour): JsonResponse
    {
        abort_unless(in_array($tour->status, ['draft', 'confirmed']), 422, 'Không thể hủy tour ở trạng thái này.');
        $tour = $this->tourService->cancel($tour);

        $tour->load(['customer', 'createdBy', 'operatedBy', 'services.supplier']);
        $this->loadPaymentHistory($tour);

        $this->activityLog->log($this->orgId(), Auth::id(), 'cancelled', "Hủy tour #{$tour->tour_number}", $tour->id);

        return response()->json(['data' => $this->format($tour)]);
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
            $tourLocked->increment('version');
        });

        $fresh = $tour->fresh(['customer', 'createdBy', 'operatedBy', 'services.supplier', 'extraRevenues']);
        $this->loadPaymentHistory($fresh);

        $amount = number_format((float) $validated['amount'], 0, ',', '.');
        $this->activityLog->log($this->orgId(), Auth::id(), 'collected', "Thu tiền khách {$amount}₫ - Tour #{$tour->tour_number}", $tour->id);

        return response()->json(['data' => $this->format($fresh)]);
    }

    public function storeExtraRevenue(Request $request, Tour $tour): JsonResponse
    {
        abort_if($tour->status === 'completed', 422, 'Tour đã hoàn thành, không thể thêm khoản thu phát sinh.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $qty = (int) ($validated['quantity'] ?? 1) ?: 1;
        $unitPrice = (float) ($validated['unit_price'] ?? 0);

        $item = $tour->extraRevenues()->create([
            'organization_id' => $tour->organization_id,
            'name' => $validated['name'],
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'amount' => $qty * $unitPrice,
            'notes' => $validated['notes'] ?? null,
        ]);

        $tour->increment('extra_revenues_total', $item->amount);
        $tour->refresh();
        $this->recalculateTotalAmount($tour);

        return response()->json(['data' => $this->formatExtraRevenue($item)], 201);
    }

    public function updateExtraRevenue(Request $request, TourExtraRevenue $extraRevenue): JsonResponse
    {
        abort_unless($extraRevenue->tour->organization_id === $this->orgId(), 403);
        abort_if($extraRevenue->tour->status === 'completed', 422, 'Tour đã hoàn thành, không thể sửa khoản thu phát sinh.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $qty = (int) ($validated['quantity'] ?? 1) ?: 1;
        $unitPrice = (float) ($validated['unit_price'] ?? 0);

        $extraRevenue->update([
            'name' => $validated['name'],
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'amount' => $qty * $unitPrice,
            'notes' => $validated['notes'] ?? null,
        ]);

        $t = $extraRevenue->tour;
        $t->update(['extra_revenues_total' => $t->extraRevenues()->sum('amount')]);
        $t->refresh();
        $this->recalculateTotalAmount($t);

        return response()->json(['data' => $this->formatExtraRevenue($extraRevenue)]);
    }

    public function destroyExtraRevenue(TourExtraRevenue $extraRevenue): JsonResponse
    {
        abort_unless($extraRevenue->tour->organization_id === $this->orgId(), 403);
        abort_if($extraRevenue->tour->status === 'completed', 422, 'Tour đã hoàn thành, không thể xóa khoản thu phát sinh.');

        $tour = $extraRevenue->tour;
        $extraRevenue->delete();
        $tour->decrement('extra_revenues_total', $extraRevenue->amount);
        $tour->refresh();
        $this->recalculateTotalAmount($tour);

        return response()->json(null, 204);
    }

    public function toggleFeatured(Tour $tour): JsonResponse
    {
        $tour->update(['is_featured' => ! $tour->is_featured]);

        return response()->json(['data' => ['id' => $tour->id, 'is_featured' => $tour->is_featured]]);
    }

    public function destroy(Tour $tour): JsonResponse
    {
        abort_unless($tour->status === 'draft', 422, 'Chỉ có thể xóa tour ở trạng thái nháp.');

        $this->activityLog->log($this->orgId(), Auth::id(), 'deleted', "Xóa tour #{$tour->tour_number}: {$tour->name}");

        $tour->delete();

        return response()->json(null, 204);
    }

    private function recalculateTotalAmount(Tour $tour): void
    {
        $tour->update([
            'total_amount' => (float) $tour->subtotal + (float) $tour->tax_amount + (float) $tour->extra_revenues_total,
        ]);
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
            'vat_rate' => (float) ($tour->vat_rate ?? 0),
            'subtotal' => (float) ($tour->subtotal ?? 0),
            'tax_amount' => (float) ($tour->tax_amount ?? 0),
            'total_amount' => $tour->total_amount,
            'paid_amount' => $tour->paid_amount,
            'receivable' => max(0, (float) $tour->total_amount - (float) $tour->paid_amount),
            'services_cost' => (float) ($tour->services_cost_total ?? $tour->services?->sum(fn ($s) => (float) $s->cost) ?? 0),
            'services_paid' => (float) ($tour->services_paid_total ?? $tour->services?->sum(fn ($s) => (float) $s->paid_amount) ?? 0),
            'payable' => max(0, (float) ($tour->services_cost_total ?? 0) - (float) ($tour->services_paid_total ?? 0)),
            'extra_revenues_total' => (float) $tour->extra_revenues_total,
            'status' => $tour->status,
            'stage' => $tour->stage ?? 'quote',
            'version' => (int) $tour->version,
            'is_featured' => (bool) $tour->is_featured,
            'created_by' => $tour->created_by,
            'created_by_name' => $tour->createdBy?->name,
            'operated_by' => $tour->operated_by,
            'operated_by_name' => $tour->operatedBy?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function formatExtraRevenue(TourExtraRevenue $r): array
    {
        return [
            'id' => $r->id,
            'tour_id' => $r->tour_id,
            'name' => $r->name,
            'quantity' => (int) $r->quantity,
            'unit_price' => (float) $r->unit_price,
            'amount' => (float) $r->amount,
            'notes' => $r->notes,
        ];
    }

    private function syncExtraRevenues(Tour $tour, array $items): void
    {
        $incomingIds = collect($items)->pluck('id')->filter()->all();

        $tour->extraRevenues()->whereNotIn('id', $incomingIds)->delete();

        foreach ($items as $item) {
            $qty = (int) ($item['quantity'] ?? 1) ?: 1;
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $data = [
                'name' => $item['name'],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'amount' => $qty * $unitPrice,
                'notes' => $item['notes'] ?? null,
            ];

            if (! empty($item['id'])) {
                TourExtraRevenue::where('id', $item['id'])->update($data);
            } else {
                $tour->extraRevenues()->create(array_merge($data, [
                    'organization_id' => $tour->organization_id,
                ]));
            }
        }

        $tour->update(['extra_revenues_total' => $tour->extraRevenues()->sum('amount')]);
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
            ->whereHas('payment')
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
        $operatingServices = $tour->services->where('service_stage', 'operating');
        $servicesCost = ($operatingServices->isNotEmpty() ? $operatingServices : $tour->services)->sum(fn ($s) => (float) $s->cost);
        $paidServices = $operatingServices->isNotEmpty()
            ? $tour->services->whereIn('service_stage', ['operating', 'settlement'])
            : $tour->services;
        $servicesPaid = $paidServices->sum(fn ($s) => (float) $s->paid_amount);

        $tour->services_cost_total = $servicesCost;
        $tour->services_paid_total = $servicesPaid;

        $customerPayments = $tour->customerPayments ?? collect();
        $servicePayments = $tour->servicePayments ?? collect();
        $guideAdvances = $tour->guideAdvances ?? collect();
        $extraRevenues = $tour->extraRevenues ?? collect();

        $guideAdvanceApproved = TourPaymentRequest::withoutGlobalScopes()
            ->where('tour_id', $tour->id)
            ->where('request_type', 'guide_advance')
            ->where('status', 'approved')
            ->exists();

        return array_merge($this->formatList($tour), [
            'notes' => $tour->notes,
            'guide_advance_approved' => $guideAdvanceApproved,
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
                'request_type' => $p->request_type ?? 'service',
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
            'extra_revenues' => $extraRevenues->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'quantity' => (int) $r->quantity,
                'unit_price' => (float) $r->unit_price,
                'amount' => (float) $r->amount,
                'notes' => $r->notes,
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
                'tax_rate' => (float) ($s->tax_rate ?? 0),
                'cost' => $s->cost,
                'paid_amount' => $s->paid_amount,
                'advance_amount' => (float) ($s->advance_amount ?? 0),
                'guide_paid_amount' => (float) ($s->guide_paid_amount ?? 0),
                'pending_amount' => (float) ($s->pending_amount ?? 0),
                'service_stage' => $s->service_stage ?? 'quote',
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
        $taxRate = (float) ($svc['tax_rate'] ?? 0);
        $baseCost = $unitPrice * $quantity * $days;

        return [
            'service_stage' => $svc['service_stage'] ?? 'quote',
            'service_type' => $svc['service_type'],
            'name' => $svc['name'],
            'supplier_id' => $svc['supplier_id'] ?? null,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'days' => $days,
            'tax_rate' => $taxRate,
            'cost' => round($baseCost * (1 + $taxRate / 100), 2),
            // paid_amount KHÔNG nằm ở đây: chỉ thay đổi qua lệnh thanh toán/thu tiền (approve/collect),
            // không cho phép sửa tour / điều hành / quyết toán ghi đè.
            'advance_amount' => (float) ($svc['advance_amount'] ?? 0),
            'guide_paid_amount' => (float) ($svc['guide_paid_amount'] ?? 0),
            'notes' => $svc['notes'] ?? null,
        ];
    }

    private function generateTourNumber(): string
    {
        $raw = Organization::find($this->orgId())?->setting('tour_number_prefix', 'TOUR') ?? 'TOUR';
        $prefix = strtoupper(trim((string) $raw)) ?: 'TOUR';

        $last = Tour::withoutGlobalScopes()->orderByDesc('id')->lockForUpdate()->first();
        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last->tour_number, $m)) {
            $seq = (int) $m[1] + 1;
        }

        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
