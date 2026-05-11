<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentType;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    use ScopedByOrganization;

    public function __construct(protected PaymentService $paymentService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $payments = Payment::with(['company', 'account', 'expenseAccount'])
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->company_id, fn ($q, $v) => $q->where('company_id', $v))
            ->latest()
            ->paginate(20);

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
        $hasExpenseAccount = $request->filled('expense_account_id');
        $isApplyingAdvance = $request->boolean('is_advance') && $request->filled('reference_id');

        $validated = $request->validate([
            'type' => ['required', Rule::enum(PaymentType::class)],
            'company_id' => [$hasExpenseAccount ? 'nullable' : 'required', 'exists:companies,id'],
            'account_id' => [$isApplyingAdvance ? 'nullable' : 'required', 'nullable', 'exists:accounts,id'],
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
                $remaining = (float) $order->total_amount - (float) $order->paid_amount;
                if ((float) $validated['amount'] > $remaining + 0.01) {
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
            if ((float) $validated['amount'] > $available + 0.01) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf(
                        'Số tiền áp dụng (%s₫) vượt quá tiền thu trước còn lại (%s₫).',
                        number_format($validated['amount'], 0, ',', '.'),
                        number_format($available, 0, ',', '.')
                    )],
                ]);
            }
        }

        $payment = $this->paymentService->create(array_merge($validated, ['organization_id' => $this->orgId()]));

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    public function show(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment->load(['company', 'account']));
    }
}
