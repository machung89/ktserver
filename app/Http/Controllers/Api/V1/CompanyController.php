<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyType;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Models\Company;
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
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('tax_code', 'like', "%{$v}%"))
            ->paginate(min((int) $request->input('per_page', 20), 500));

        return CompanyResource::collection($companies);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string'],
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

        $company = Company::create(array_merge($validated, ['organization_id' => $this->orgId()]));

        return (new CompanyResource($company->load(['assignedUser', 'bank'])))->response()->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company->load(['assignedUser', 'bank']));
    }

    public function stats(Company $company): JsonResponse
    {
        $agg = fn ($relation) => $company->{$relation}()
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

            $taxCode = $row['tax_code'] ?: null;
            if ($taxCode && isset($existingTaxCodes[$taxCode])) {
                $errors[] = ['row' => $rowNum, 'name' => $name, 'reason' => 'Mã số thuế đã tồn tại'];

                continue;
            }

            try {
                Company::create([
                    'organization_id' => $orgId,
                    'name' => $name,
                    'type' => $row['type'],
                    'tax_code' => $taxCode,
                    'phone' => $row['phone'] ?: null,
                    'email' => $row['email'] ?: null,
                    'address' => $row['address'] ?: null,
                    'city' => $row['city'] ?: null,
                    'representative' => $row['representative'] ?: null,
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
