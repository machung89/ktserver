<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    use ScopedByOrganization;

    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->orderBy('id')->get()
            ->map(fn ($r) => $this->format($r));

        return response()->json(['data' => $roles]);
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::orderBy('module')->orderBy('name')->get()
            ->groupBy('module')
            ->map(fn ($items, $module) => [
                'module' => $module,
                'permissions' => $items->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'display_name' => $p->display_name]),
            ])
            ->values();

        return response()->json(['data' => $permissions]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('roles')->where('organization_id', $this->orgId())],
            'display_name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $role = Role::create(array_merge($data, ['organization_id' => $this->orgId()]));
        $role->permissions()->sync($data['permission_ids'] ?? []);

        return response()->json(['data' => $this->format($role->load('permissions'))], 201);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json(['data' => $this->format($role->load('permissions'))]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'display_name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:255',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        if ($role->is_system) {
            // Vai trò hệ thống chỉ được cập nhật permissions
            $role->permissions()->sync($data['permission_ids'] ?? []);
        } else {
            $role->update($data);
            $role->permissions()->sync($data['permission_ids'] ?? []);
        }

        return response()->json(['data' => $this->format($role->load('permissions'))]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json(['message' => 'Không thể xóa vai trò hệ thống'], 422);
        }

        $role->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function format(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'permission_ids' => $role->permissions->pluck('id'),
            'permissions' => $role->permissions->groupBy('module')->map(fn ($items, $mod) => [
                'module' => $mod,
                'items' => $items->pluck('display_name'),
            ])->values(),
        ];
    }
}
