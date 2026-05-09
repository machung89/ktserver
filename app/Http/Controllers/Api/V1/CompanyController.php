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
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        $companies = Company::query()
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('tax_code', 'like', "%{$v}%"))
            ->paginate(20);

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
            'representative' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $company = Company::create(array_merge($validated, ['organization_id' => $this->orgId()]));

        return (new CompanyResource($company))->response()->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company);
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
            'representative' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $company->update($validated);

        return new CompanyResource($company);
    }

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();

        return response()->json(null, 204);
    }
}
