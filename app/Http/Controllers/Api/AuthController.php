<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\DefaultAccountsService;
use App\Services\DefaultRolesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected DefaultAccountsService $defaultAccountsService,
        protected DefaultRolesService $defaultRolesService,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'company_tax_code' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        [$user, $token] = DB::transaction(function () use ($validated) {
            $org = Organization::create([
                'name' => $validated['company_name'],
                'tax_code' => $validated['company_tax_code'] ?? null,
                'address' => $validated['company_address'] ?? null,
            ]);

            $user = User::create([
                'organization_id' => $org->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'position' => 'Quản trị viên',
            ]);

            $this->defaultAccountsService->seedForOrganization($org->id);
            $this->defaultRolesService->seedForOrganization($org->id);

            // Tự động gán role admin cho người đăng ký tổ chức
            $adminRole = Role::withoutGlobalScopes()
                ->where('organization_id', $org->id)
                ->where('name', 'admin')
                ->first();
            if ($adminRole) {
                $user->roles()->attach($adminRole->id);
            }

            return [$user, $user->createToken('auth_token')->plainTextToken];
        });

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with('organization')->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email hoặc mật khẩu không đúng.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Tài khoản đã bị vô hiệu hóa.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'position' => $user->position,
            'organization' => $user->organization ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
                'tax_code' => $user->organization->tax_code,
            ] : null,
        ];
    }
}
