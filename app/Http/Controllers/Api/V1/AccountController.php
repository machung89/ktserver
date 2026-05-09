<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = Account::query()
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->boolean('active_only', false), fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();

        return AccountResource::collection($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', Rule::unique('accounts', 'code')->where('organization_id', $this->orgId())],
            'name' => ['required', 'string'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'parent_code' => ['nullable', 'string', Rule::exists('accounts', 'code')->where('organization_id', $this->orgId())],
            'is_active' => ['boolean'],
        ]);

        $account = Account::create(array_merge($validated, ['organization_id' => $this->orgId()]));

        return (new AccountResource($account))->response()->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($account);
    }

    public function update(Request $request, Account $account): AccountResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string'],
            'type' => ['sometimes', Rule::enum(AccountType::class)],
            'parent_code' => ['nullable', 'string', Rule::exists('accounts', 'code')->where('organization_id', $this->orgId())],
            'is_active' => ['boolean'],
        ]);

        $account->update($validated);

        return new AccountResource($account);
    }
}
