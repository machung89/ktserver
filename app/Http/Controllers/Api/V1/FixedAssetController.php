<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Services\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetController extends Controller
{
    use ScopedByOrganization;

    public function __construct(protected FixedAssetService $fixedAssetService) {}

    public function index(): JsonResponse
    {
        $assets = FixedAsset::with(['company', 'account', 'expenseAccount'])
            ->withCount('depreciations')
            ->latest()
            ->get();

        return response()->json(['data' => $assets]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'expense_account_id' => ['nullable', 'exists:accounts,id'],
            'purchase_date' => ['required', 'date'],
            'depreciation_start_date' => ['required', 'date'],
            'cost' => ['required', 'numeric', 'min:0.01'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_years' => ['required', 'integer', 'min:1', 'max:50'],
            'asset_account_code' => ['nullable', 'string'],
            'accumulated_depreciation_account_code' => ['nullable', 'string'],
        ]);

        $validated['organization_id'] = $this->orgId();
        $validated['residual_value'] ??= 0;
        $validated['asset_account_code'] ??= '211';
        $validated['accumulated_depreciation_account_code'] ??= '214';

        $asset = $this->fixedAssetService->create($validated);

        return response()->json(['data' => $asset->load(['company', 'account', 'expenseAccount'])], 201);
    }

    public function show(FixedAsset $fixedAsset): JsonResponse
    {
        $fixedAsset->load(['company', 'account', 'expenseAccount', 'depreciations']);

        return response()->json([
            'data' => $fixedAsset,
            'depreciation_schedule' => $fixedAsset->depreciationSchedule(),
        ]);
    }

    public function postDepreciation(Request $request, FixedAsset $fixedAsset): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $depreciation = $this->fixedAssetService->postDepreciation($fixedAsset, $validated['year']);

        return response()->json(['data' => $depreciation], 201);
    }
}
