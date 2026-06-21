<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class EmployeeController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): JsonResponse
    {
        $users = User::with(['roles', 'viewableUsers'])
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
            'password' => ['nullable', Password::min(8)],
            'phone' => 'required|string|max:20|unique:users',
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
        ]);

        // Để trống mật khẩu → tự sinh; nhân viên buộc đổi mật khẩu ở lần đăng nhập đầu.
        $plainPassword = ! empty($data['password']) ? $data['password'] : Str::password(10, true, true, false, false);

        $user = User::create([
            ...$data,
            'password' => Hash::make($plainPassword),
            'must_change_password' => true,
            'organization_id' => $this->orgId(),
        ]);

        if (! empty($data['role_ids'])) {
            $user->roles()->sync($data['role_ids']);
        }

        // Trả mật khẩu vừa sinh để quản trị viên gửi cho nhân viên (chỉ hiển thị một lần lúc tạo).
        return response()->json([
            'data' => $this->format($user->load('roles')),
            'generated_password' => $plainPassword,
        ], 201);
    }

    public function show(User $employee): JsonResponse
    {
        return response()->json(['data' => $this->format($employee->load(['roles', 'viewableUsers']))]);
    }

    public function update(Request $request, User $employee): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => "sometimes|required|email|unique:users,email,{$employee->id}",
            'password' => ['nullable', Password::min(8)],
            'phone' => "sometimes|required|string|max:20|unique:users,phone,{$employee->id}",
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'exists:roles,id',
            'viewable_user_ids' => 'nullable|array',
            'viewable_user_ids.*' => 'exists:users,id',
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $employee->update($data);

        // Khóa tài khoản → thu hồi mọi token đang đăng nhập (đăng xuất ngay lập tức)
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $employee->tokens()->delete();
        }

        if (array_key_exists('role_ids', $data)) {
            $employee->roles()->sync($data['role_ids'] ?? []);
        }

        if (array_key_exists('viewable_user_ids', $data)) {
            $employee->viewableUsers()->sync($data['viewable_user_ids'] ?? []);
        }

        return response()->json(['data' => $this->format($employee->load(['roles', 'viewableUsers']))]);
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
            'viewable_user_ids' => $user->relationLoaded('viewableUsers')
                ? $user->viewableUsers->pluck('id')->all()
                : [],
        ];
    }
}
