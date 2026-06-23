<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyType;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Models\Company;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        $companies = Company::with(['assignedUser', 'bank'])
            ->when($request->boolean('with_balance'), fn ($q) => $q->addSelect([
                'receivable_balance' => SalesOrder::selectRaw('COALESCE(SUM(total_amount) - SUM(paid_amount), 0)')
                    ->whereColumn('company_id', 'companies.id')
                    ->whereIn('status', ['confirmed', 'shipping', 'completed']),
            ]))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->search, fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('name', 'like', "%{$v}%")
                    ->orWhere('phone', 'like', "%{$v}%")
                    ->orWhere('address', 'like', "%{$v}%");
            }))
            ->paginate(min((int) $request->input('per_page', 20), 500));

        return CompanyResource::collection($companies);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('companies', 'code')->where('organization_id', $this->orgId())],
            'type' => ['required', Rule::enum(CompanyType::class)],
            'tax_code' => ['nullable', 'string', Rule::unique('companies', 'tax_code')->where('organization_id', $this->orgId())],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'ward' => ['nullable', 'string'],
            'representative' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'user_id' => ['nullable', 'exists:users,id'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
        ]);

        $validated['user_id'] ??= auth()->id();
        $validated['code'] = ($validated['code'] ?? null) ?: Company::generateCode($this->orgId(), $validated['type']);

        $company = Company::create(array_merge($validated, ['organization_id' => $this->orgId()]));

        return (new CompanyResource($company->load(['assignedUser', 'bank'])))->response()->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company->load(['assignedUser', 'bank']));
    }

    public function stats(Company $company): JsonResponse
    {
        // Chỉ tính đơn đã lên sổ (xác nhận/đang giao/hoàn thành) — bỏ nháp/đã hủy,
        // để Doanh thu/Đã thu/Còn nợ nhất quán với báo cáo công nợ & receivable_balance.
        $agg = fn ($relation) => $company->{$relation}()
            ->whereIn('status', ['confirmed', 'shipping', 'completed'])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total, COALESCE(SUM(paid_amount),0) as paid')
            ->first();

        $sales = $agg('salesOrders');
        $purchases = $agg('purchaseOrders');

        return response()->json([
            'sales' => [
                'count' => (int) $sales->cnt,
                'total' => (float) $sales->total,
                'paid' => (float) $sales->paid,
                'debt' => max(0, (float) $sales->total - (float) $sales->paid),
            ],
            'purchases' => [
                'count' => (int) $purchases->cnt,
                'total' => (float) $purchases->total,
                'paid' => (float) $purchases->paid,
                'debt' => max(0, (float) $purchases->total - (float) $purchases->paid),
            ],
        ]);
    }

    public function update(Request $request, Company $company): CompanyResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('companies', 'code')->ignore($company->id)->where('organization_id', $this->orgId())],
            'type' => ['sometimes', Rule::enum(CompanyType::class)],
            'tax_code' => ['nullable', 'string', Rule::unique('companies', 'tax_code')->ignore($company->id)->where('organization_id', $this->orgId())],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'ward' => ['nullable', 'string'],
            'representative' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'user_id' => ['nullable', 'exists:users,id'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
        ]);

        $company->update($validated);

        return new CompanyResource($company->load(['assignedUser', 'bank']));
    }

    public function destroy(Company $company): JsonResponse
    {
        $salesCount = SalesOrder::where('company_id', $company->id)->count();
        $purchaseCount = PurchaseOrder::where('company_id', $company->id)->count();
        $paymentCount = Payment::where('company_id', $company->id)->count();

        if ($salesCount + $purchaseCount + $paymentCount > 0) {
            $parts = array_filter([
                $salesCount > 0 ? "{$salesCount} đơn bán" : null,
                $purchaseCount > 0 ? "{$purchaseCount} đơn nhập" : null,
                $paymentCount > 0 ? "{$paymentCount} phiếu thu/chi" : null,
            ]);

            return response()->json([
                'message' => 'Không thể xóa vì đang có liên kết: '.implode(', ', $parts).'. Vui lòng xóa các liên kết trước.',
            ], 422);
        }

        $company->delete();

        return response()->json(null, 204);
    }

    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate(['rows' => ['required', 'array', 'min:1']]);

        $orgId = $this->orgId();
        $existingNames = Company::pluck('name')
            ->map(fn ($n) => strtolower($n))
            ->flip()
            ->all();
        $existingTaxCodes = Company::whereNotNull('tax_code')
            ->pluck('tax_code')
            ->flip()
            ->all();

        $success = 0;
        $errors = [];

        foreach ($request->rows as $i => $row) {
            $rowNum = $i + 2;

            // Bỏ qua cột loại → mặc định Khách hàng
            if (empty($row['type'])) {
                $row['type'] = CompanyType::Customer->value;
            }

            $v = Validator::make($row, [
                'name' => 'required|string',
                'type' => ['required', Rule::enum(CompanyType::class)],
            ]);

            if ($v->fails()) {
                $errors[] = ['row' => $rowNum, 'name' => $row['name'] ?? '—', 'reason' => implode('; ', $v->errors()->all())];

                continue;
            }

            $name = trim($row['name']);
            if (isset($existingNames[strtolower($name)])) {
                $errors[] = ['row' => $rowNum, 'name' => $name, 'reason' => 'Tên đã tồn tại'];

                continue;
            }

            $taxCode = ($row['tax_code'] ?? null) ?: null;
            if ($taxCode && isset($existingTaxCodes[$taxCode])) {
                $errors[] = ['row' => $rowNum, 'name' => $name, 'reason' => 'Mã số thuế đã tồn tại'];

                continue;
            }

            try {
                Company::create([
                    'organization_id' => $orgId,
                    'name' => $name,
                    'code' => ($row['code'] ?? null) ?: Company::generateCode($orgId, $row['type']),
                    'type' => $row['type'],
                    'tax_code' => $taxCode,
                    'phone' => ($row['phone'] ?? null) ?: null,
                    'email' => ($row['email'] ?? null) ?: null,
                    'address' => ($row['address'] ?? null) ?: null,
                    'city' => ($row['city'] ?? null) ?: null,
                    'representative' => ($row['representative'] ?? null) ?: null,
                    'is_active' => true,
                ]);
                $existingNames[strtolower($name)] = true;
                if ($taxCode) {
                    $existingTaxCodes[$taxCode] = true;
                }
                $success++;
            } catch (\Throwable) {
                $errors[] = ['row' => $rowNum, 'name' => $name, 'reason' => 'Lỗi không xác định'];
            }
        }

        return response()->json(['success' => $success, 'failed' => count($errors), 'errors' => $errors]);
    }
}
