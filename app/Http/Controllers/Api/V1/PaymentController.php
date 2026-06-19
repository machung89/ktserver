<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Tour;
use App\Models\TourPaymentRequest;
use App\Models\TourService;
use App\Services\ActivityLogService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    use ScopedByOrganization;

    public function __construct(
        protected PaymentService $paymentService,
        protected ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $payments = Payment::with(['company.bank', 'account', 'toAccount', 'expenseAccount', 'allocations.salesOrder:id,order_number'])
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->company_id, fn ($q, $v) => $q->where('company_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->search, function ($q, $v) {
                $q->where(function ($q) use ($v) {
                    $q->where('payment_number', 'like', "%{$v}%")
                        ->orWhere('description', 'like', "%{$v}%")
                        ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$v}%"));
                });
            })
            ->latest()
            ->paginate($request->filled('per_page') ? (int) $request->per_page : 20);

        return PaymentResource::collection($payments);
    }

    public function advanceBalance(Request $request): JsonResponse
    {
        $request->validate(['company_id' => ['required', 'exists:companies,id']]);

        $balance = $this->paymentService->availableAdvance(
            (int) $request->company_id,
            $this->orgId()
        );

        return response()->json(['balance' => $balance]);
    }

    public function store(Request $request): JsonResponse
    {
        $isTransfer = $request->input('type') === PaymentType::Transfer->value;
        $hasExpenseAccount = $request->filled('expense_account_id');
        $isApplyingAdvance = $request->boolean('is_advance') && $request->filled('reference_id');

        $validated = $request->validate([
            'type' => ['required', Rule::enum(PaymentType::class)],
            'company_id' => [$isTransfer || $hasExpenseAccount ? 'nullable' : 'required', 'nullable', 'exists:companies,id'],
            'account_id' => [$isApplyingAdvance ? 'nullable' : 'required', 'nullable', 'exists:accounts,id'],
            'to_account_id' => [$isTransfer ? 'required' : 'nullable', 'nullable', 'exists:accounts,id', 'different:account_id'],
            'expense_account_id' => ['nullable', 'exists:accounts,id'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string'],
            'reference_type' => ['nullable', 'string'],
            'reference_id' => ['nullable', 'integer'],
            'is_advance' => ['boolean'],
        ]);

        // Kiểm tra số tiền thanh toán không vượt quá số tiền còn nợ
        if (! empty($validated['reference_type']) && ! empty($validated['reference_id'])) {
            $order = $validated['reference_type']::find($validated['reference_id']);
            if ($order) {
                $totalAmount = (float) $order->total_amount;
                $paidAmount = (float) $order->paid_amount;
                $remaining = $totalAmount < 0
                    ? abs($totalAmount) - abs($paidAmount)
                    : $totalAmount - $paidAmount;
                // VND: so sánh ở mức đồng nguyên, tránh chặn nhầm khi dữ liệu cũ còn đồng lẻ
                if (round((float) $validated['amount']) > round($remaining)) {
                    throw ValidationException::withMessages([
                        'amount' => [sprintf(
                            'Số tiền thanh toán (%s₫) vượt quá số tiền còn nợ (%s₫).',
                            number_format($validated['amount'], 0, ',', '.'),
                            number_format($remaining, 0, ',', '.')
                        )],
                    ]);
                }
            }
        }

        // Kiểm tra tiền thu trước còn đủ khi áp vào đơn hàng
        if ($isApplyingAdvance && ! empty($validated['company_id'])) {
            $available = $this->paymentService->availableAdvance(
                (int) $validated['company_id'],
                $this->orgId()
            );
            if (round((float) $validated['amount']) > round($available)) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf(
                        'Số tiền áp dụng (%s₫) vượt quá tiền thu trước còn lại (%s₫).',
                        number_format($validated['amount'], 0, ',', '.'),
                        number_format($available, 0, ',', '.')
                    )],
                ]);
            }
        }

        // Dùng tiền thu trước cho đơn BÁN → phân bổ (không tạo phiếu thu thừa, không bút toán mới)
        $isSalesAdvance = $isApplyingAdvance
            && ($validated['reference_type'] ?? null) === SalesOrder::class
            && $validated['type'] === PaymentType::Receipt->value;

        if ($isSalesAdvance) {
            $this->paymentService->applyAdvanceToOrder(
                (int) $validated['company_id'],
                (int) $validated['reference_id'],
                (float) $validated['amount'],
                $this->orgId(),
            );

            $this->activityLog->log(
                $this->orgId(), Auth::id(),
                'advance_applied',
                'Áp tiền thu trước '.number_format((float) $validated['amount']).'đ vào đơn',
                null, [], 'sales_order', (int) $validated['reference_id']
            );

            return response()->json(['applied' => round((float) $validated['amount'])], 201);
        }

        $payment = $this->paymentService->create(array_merge($validated, ['organization_id' => $this->orgId()]));

        $typeLabels = [
            PaymentType::Receipt->value => 'Thu tiền',
            PaymentType::Payment->value => 'Chi tiền',
            PaymentType::Transfer->value => 'Chuyển khoản',
        ];

        // Gắn nhật ký vào đơn bán/nhập nếu phiếu liên kết, để hiển thị trong lịch sử đơn
        $logSubjectType = match ($validated['reference_type'] ?? null) {
            SalesOrder::class => 'sales_order',
            PurchaseOrder::class => 'purchase_order',
            default => 'payment',
        };
        $logSubjectId = $logSubjectType === 'payment' ? $payment->id : (int) $validated['reference_id'];

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'payment_created',
            'Tạo phiếu '.($typeLabels[$validated['type']] ?? '').' '.$payment->payment_number.': '.number_format((float) $validated['amount']).'đ',
            null, [], $logSubjectType, $logSubjectId
        );

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    /**
     * Thu tiền gộp: khách trả một cục cho nhiều đơn còn nợ.
     * Hệ thống tự quét các đơn còn nợ và phân bổ theo FIFO (đơn cũ trước) tới khi hết tiền.
     * Phần dư (nếu vượt tổng nợ) ghi nhận thành tiền thu trước cho khách.
     */
    public function bulkReceipt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'account_id' => ['required', 'exists:accounts,id'],
            'payment_date' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string'],
            'keep_excess_as_advance' => ['boolean'],
            // Tùy chỉnh: phân bổ tay từng đơn. Rỗng => phân bổ tự động FIFO theo `amount`.
            'allocations' => ['nullable', 'array'],
            'allocations.*.sales_order_id' => ['required_with:allocations', 'integer'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0'],
        ]);

        $orgId = $this->orgId();
        $companyId = (int) $validated['company_id'];
        $keepExcess = $request->boolean('keep_excess_as_advance', true);

        // Chọn tay: chỉ giữ dòng có tiền > 0
        $custom = collect($validated['allocations'] ?? [])
            ->filter(fn ($a) => round((float) $a['amount']) > 0)
            ->values();

        if ($custom->isEmpty() && empty($validated['amount'])) {
            throw ValidationException::withMessages(['amount' => ['Nhập số tiền hoặc chọn đơn để thu.']]);
        }

        $result = DB::transaction(function () use ($validated, $orgId, $companyId, $keepExcess, $custom) {
            $desc = ($validated['description'] ?? null) ?: 'Thu gộp nhiều đơn';
            $allocations = [];   // dùng để ghi payment_allocations
            $summary = [];       // trả về cho frontend

            if ($custom->isNotEmpty()) {
                // ── Phân bổ TAY: chỉ áp vào đúng các đơn đã chọn, mỗi đơn không vượt số còn nợ ──
                $orders = SalesOrder::where('company_id', $companyId)
                    ->whereIn('id', $custom->pluck('sales_order_id')->all())
                    ->whereIn('status', [OrderStatus::Confirmed, OrderStatus::Shipping, OrderStatus::Completed])
                    ->where('total_amount', '>', 0)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($custom as $c) {
                    $order = $orders->get((int) $c['sales_order_id']);
                    if (! $order) {
                        continue;
                    }
                    $remaining = round((float) $order->total_amount - (float) $order->paid_amount);
                    $pay = min(round((float) $c['amount']), max(0, $remaining));
                    if ($pay <= 0) {
                        continue;
                    }
                    $allocations[] = ['sales_order_id' => $order->id, 'amount' => $pay];
                    $summary[] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'paid' => $pay,
                        'fully_paid' => ($remaining - $pay) <= 0,
                    ];
                }

                $allocatedSum = (float) array_sum(array_column($allocations, 'amount'));
                // Số tiền thật = số nhập (nếu có & lớn hơn) để phần dư thành credit; mặc định = tổng đã áp
                $totalAmount = max($allocatedSum, round((float) ($validated['amount'] ?? 0)));
                $leftover = $totalAmount - $allocatedSum;
            } else {
                // ── Phân bổ TỰ ĐỘNG FIFO theo số tiền nhập ──
                $totalAmount = round((float) $validated['amount']);
                $leftover = $totalAmount;

                $orders = SalesOrder::where('company_id', $companyId)
                    ->whereIn('status', [OrderStatus::Confirmed, OrderStatus::Shipping, OrderStatus::Completed])
                    ->whereIn('payment_status', [PaymentStatus::Unpaid, PaymentStatus::Partial])
                    ->where('total_amount', '>', 0)
                    ->orderBy('order_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($orders as $order) {
                    if ($leftover <= 0) {
                        break;
                    }
                    $remaining = round((float) $order->total_amount - (float) $order->paid_amount);
                    if ($remaining <= 0) {
                        continue;
                    }
                    $pay = min($remaining, $leftover);
                    $leftover -= $pay;

                    $allocations[] = ['sales_order_id' => $order->id, 'amount' => $pay];
                    $summary[] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'paid' => $pay,
                        'fully_paid' => ($remaining - $pay) <= 0,
                    ];
                }
            }

            // MỘT phiếu thu duy nhất cho cả lần khách đưa tiền (1 chứng từ, 1 bút toán).
            $allocatedSum = $totalAmount - $leftover;
            $received = $keepExcess ? $totalAmount : $allocatedSum;
            $advance = $keepExcess ? $leftover : 0;

            if ($received > 0) {
                $this->paymentService->createReceiptWithAllocations([
                    'company_id' => $companyId,
                    'account_id' => (int) $validated['account_id'],
                    'payment_date' => $validated['payment_date'],
                    'amount' => $received,
                    'description' => $desc,
                    'organization_id' => $orgId,
                ], $allocations);
            }

            return [
                'allocations' => $summary,
                'advance' => $advance,
                'unallocated' => $keepExcess ? 0 : $leftover,
                'allocated_amount' => $allocatedSum,
            ];
        });

        $allocatedToOrders = $result['allocated_amount'];

        $this->activityLog->log(
            $orgId, Auth::id(),
            'payment_bulk_receipt',
            'Thu gộp '.number_format($allocatedToOrders).'đ cho '.count($result['allocations']).' đơn'
                .($result['advance'] > 0 ? ' (+ '.number_format($result['advance']).'đ thu trước)' : ''),
            null, [], 'company', $companyId
        );

        return response()->json([
            'allocated_orders' => count($result['allocations']),
            'allocated_amount' => $allocatedToOrders,
            'allocations' => $result['allocations'],
            'advance' => $result['advance'],
            'unallocated' => $result['unallocated'],
        ]);
    }

    /**
     * Gỡ áp một phân bổ khỏi đơn — tiền quay về credit của khách (không đụng phiếu thu/bút toán).
     */
    public function destroyAllocation(PaymentAllocation $allocation): JsonResponse
    {
        abort_unless($allocation->organization_id === $this->orgId(), 403);

        $orderId = $allocation->sales_order_id;
        $amount = (float) $allocation->amount;

        DB::transaction(function () use ($allocation, $orderId) {
            $allocation->delete();
            SalesOrder::find($orderId)?->syncPaymentStatus();
        });

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'allocation_removed',
            'Gỡ áp '.number_format($amount).'đ khỏi đơn (tiền về credit khách)',
            null, [], 'sales_order', $orderId
        );

        return response()->json(null, 204);
    }

    public function show(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment->load(['company.bank', 'account', 'toAccount', 'expenseAccount', 'createdBy:id,name', 'allocations.salesOrder:id,order_number']));
    }

    public function approve(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
        ]);

        $payment = $this->paymentService->approve($payment, (int) $validated['account_id']);

        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'payment_approved',
            'Duyệt phiếu: '.number_format((float) $payment->amount).'đ',
            null, [], 'payment', $payment->id
        );

        return (new PaymentResource($payment))->response();
    }

    public function destroy(Payment $payment): JsonResponse
    {
        $paymentId = $payment->id;
        $paymentNumber = $payment->payment_number;
        $paymentAmount = (float) $payment->amount;
        $paymentType = $payment->type instanceof PaymentType ? $payment->type->value : (string) $payment->type;

        // Nếu phiếu liên kết đơn bán/nhập → ghi nhật ký vào đơn để hiển thị trong lịch sử đơn
        $logSubjectType = match ($payment->reference_type) {
            SalesOrder::class => 'sales_order',
            PurchaseOrder::class => 'purchase_order',
            default => 'payment',
        };
        $logSubjectId = $logSubjectType === 'payment' ? $paymentId : (int) $payment->reference_id;

        DB::transaction(function () use ($payment) {
            JournalEntry::where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->each(function ($entry) {
                    $entry->lines()->delete();
                    $entry->delete();
                });

            // Hoàn paid_amount nếu phiếu thu liên kết với tour
            if ($payment->reference_type === Tour::class && $payment->reference_id) {
                Tour::withoutGlobalScopes()
                    ->where('id', $payment->reference_id)
                    ->decrement('paid_amount', (float) $payment->amount);
            }

            // Hoàn lệnh thanh toán tour về trạng thái chờ duyệt khi xóa phiếu liên kết
            if ($payment->reference_type === TourPaymentRequest::class && $payment->reference_id) {
                $paymentRequest = TourPaymentRequest::withoutGlobalScopes()->find($payment->reference_id);

                if ($paymentRequest && $paymentRequest->status === 'approved') {
                    $wasActuallyPaid = $payment->status === 'approved';

                    if ($wasActuallyPaid && $paymentRequest->service) {
                        $paymentRequest->service->decrement('paid_amount', (float) $paymentRequest->amount);
                    }

                    if (in_array($paymentRequest->request_type, ['settlement', 'settlement_receipt'])) {
                        if ($wasActuallyPaid) {
                            TourService::where('tour_id', $paymentRequest->tour_id)
                                ->where('service_stage', 'operating')
                                ->where('guide_paid_amount', '>', 0)
                                ->each(fn ($s) => $s->decrement('paid_amount', (float) $s->guide_paid_amount));
                        }
                        TourService::where('tour_id', $paymentRequest->tour_id)
                            ->where('service_stage', 'settlement')
                            ->update(['paid_amount' => 0]);
                        $paymentRequest->tour?->update(['stage' => 'operating']);
                    } elseif ($paymentRequest->request_type === 'guide_advance') {
                        $paymentRequest->tour?->update(['stage' => 'quote']);
                    }

                    $paymentRequest->update([
                        'status' => 'pending',
                        'payment_id' => null,
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);
                }
            }

            // Lấy đơn liên kết trước khi xóa để đồng bộ lại trạng thái thanh toán sau đó
            $orderRef = in_array($payment->reference_type, [SalesOrder::class, PurchaseOrder::class], true)
                ? $payment->reference
                : null;

            // Phiếu thu gộp: lấy các đơn đã phân bổ để đồng bộ lại sau khi xóa (allocations cascade)
            $allocatedOrderIds = $payment->allocations()->pluck('sales_order_id')->all();

            // Đảo các phiếu cùng nhóm thu gộp (vd phiếu thu trước phần dư) — một thao tác = hủy toàn bộ
            if ($payment->group_id) {
                $siblings = Payment::where('group_id', $payment->group_id)
                    ->where('id', '!=', $payment->id)
                    ->get();
                foreach ($siblings as $sib) {
                    JournalEntry::where('reference_type', Payment::class)
                        ->where('reference_id', $sib->id)
                        ->each(function ($entry) {
                            $entry->lines()->delete();
                            $entry->delete();
                        });
                    $sibOrderIds = $sib->allocations()->pluck('sales_order_id')->all();
                    $sib->delete();
                    foreach ($sibOrderIds as $orderId) {
                        $allocatedOrderIds[] = $orderId;
                    }
                }
            }

            $payment->delete();

            // Tính lại paid_amount & payment_status cho đơn bán/nhập (sum loại bỏ phiếu vừa xóa)
            $orderRef?->syncPaymentStatus();
            foreach (array_unique($allocatedOrderIds) as $orderId) {
                SalesOrder::find($orderId)?->syncPaymentStatus();
            }
        });

        $typeLabels = [
            PaymentType::Receipt->value => 'Thu tiền',
            PaymentType::Payment->value => 'Chi tiền',
            PaymentType::Transfer->value => 'Chuyển khoản',
        ];
        $this->activityLog->log(
            $this->orgId(), Auth::id(),
            'payment_deleted',
            'Xóa phiếu '.($typeLabels[$paymentType] ?? '').' '.$paymentNumber.': '.number_format($paymentAmount).'đ',
            null, [], $logSubjectType, $logSubjectId
        );

        return response()->json(null, 204);
    }
}
