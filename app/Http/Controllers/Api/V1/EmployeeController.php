<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class EmployeeController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): JsonResponse
    {
        $users = User::with('roles')
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%$v%")->orWhere('email', 'like', "%$v%"))
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => $this->format($u));

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => ['required', Password::min(8)],
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $user = User::create([
            ...$data,
            'password' => Hash::make($data['password']),
            'organization_id' => $this->orgId(),
        ]);

        if (! empty($data['role_ids'])) {
            $user->roles()->sync($data['role_ids']);
        }

        return response()->json(['data' => $this->format($user->load('roles'))], 201);
    }

    public function show(User $employee): JsonResponse
    {
        return response()->json(['data' => $this->format($employee->load('roles'))]);
    }

    public function update(Request $request, User $employee): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => "sometimes|required|email|unique:users,email,{$employee->id}",
            'password' => ['nullable', Password::min(8)],
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $employee->update($data);

        if (array_key_exists('role_ids', $data)) {
            $employee->roles()->sync($data['role_ids'] ?? []);
        }

        return response()->json(['data' => $this->format($employee->load('roles'))]);
    }

    public function destroy(User $employee): JsonResponse
    {
        $employee->roles()->detach();
        $employee->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function format(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'department' => $user->department,
            'position' => $user->position,
            'is_active' => $user->is_active,
            'roles' => $user->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'display_name' => $r->display_name]),
        ];
    }
}
