<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;

class DefaultRolesService
{
    public function seedForOrganization(int $organizationId): void
    {
        $permissionMap = Permission::pluck('id', 'name');

        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Quản trị viên',
                'description' => 'Toàn quyền hệ thống',
                'permissions' => $permissionMap->keys()->all(),
            ],
            [
                'name' => 'accountant',
                'display_name' => 'Kế toán',
                'description' => 'Quản lý kế toán, thu chi, báo cáo',
                'permissions' => [
                    'dashboard.view', 'products.view', 'companies.view',
                    'inventory.view', 'warehouses.view',
                    'purchases.view', 'sales.view',
                    'payments.view', 'payments.create',
                    'accounts.view', 'accounts.create', 'accounts.edit',
                    'journal.view', 'reports.view',
                ],
            ],
            [
                'name' => 'sales_staff',
                'display_name' => 'Nhân viên bán hàng',
                'description' => 'Tạo và quản lý đơn bán hàng',
                'permissions' => [
                    'dashboard.view', 'products.view',
                    'companies.view', 'companies.create', 'companies.edit',
                    'inventory.view',
                    'sales.view', 'sales.create', 'sales.edit', 'sales.confirm', 'sales.cancel',
                    'payments.view',
                ],
            ],
            [
                'name' => 'warehouse_staff',
                'display_name' => 'Thủ kho',
                'description' => 'Quản lý kho, nhập xuất hàng',
                'permissions' => [
                    'dashboard.view', 'products.view',
                    'warehouses.view', 'inventory.view',
                    'purchases.view', 'purchases.confirm',
                    'sales.view', 'sales.confirm',
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            $permissionNames = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $organizationId, 'name' => $roleData['name']],
                array_merge($roleData, ['organization_id' => $organizationId, 'is_system' => true])
            );

            $ids = array_filter(
                array_map(fn ($n) => $permissionMap[$n] ?? null, $permissionNames)
            );
            $role->permissions()->sync($ids);
        }
    }
}
