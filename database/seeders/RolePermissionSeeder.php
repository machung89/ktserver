<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Định nghĩa tất cả permissions theo module
        $definitions = [
            'dashboard' => [['dashboard.view', 'Xem dashboard']],
            'products' => [
                ['products.view', 'Xem sản phẩm'],
                ['products.create', 'Thêm sản phẩm'],
                ['products.edit', 'Sửa sản phẩm'],
                ['products.delete', 'Xóa sản phẩm'],
            ],
            'warehouses' => [
                ['warehouses.view', 'Xem kho hàng'],
                ['warehouses.create', 'Thêm kho'],
                ['warehouses.edit', 'Sửa kho'],
                ['warehouses.delete', 'Xóa kho'],
            ],
            'inventory' => [
                ['inventory.view', 'Xem tồn kho'],
                ['inventory.adjust', 'Điều chỉnh tồn kho'],
            ],
            'companies' => [
                ['companies.view', 'Xem đối tác'],
                ['companies.create', 'Thêm đối tác'],
                ['companies.edit', 'Sửa đối tác'],
                ['companies.delete', 'Xóa đối tác'],
            ],
            'purchases' => [
                ['purchases.view', 'Xem đơn nhập (của mình)'],
                ['purchases.view_all', 'Xem tất cả đơn nhập'],
                ['purchases.create', 'Tạo đơn nhập'],
                ['purchases.edit', 'Sửa đơn nhập'],
                ['purchases.confirm', 'Xác nhận đơn nhập'],
                ['purchases.cancel', 'Hủy đơn nhập'],
            ],
            'sales' => [
                ['sales.view', 'Xem đơn bán (của mình)'],
                ['sales.view_all', 'Xem tất cả đơn bán'],
                ['sales.create', 'Tạo đơn bán'],
                ['sales.edit', 'Sửa đơn bán'],
                ['sales.edit_price', 'Sửa đơn giá trong đơn bán'],
                ['sales.confirm', 'Xác nhận đơn bán'],
                ['sales.cancel', 'Hủy đơn bán'],
            ],
            'payments' => [
                ['payments.view', 'Xem thu chi'],
                ['payments.create', 'Tạo phiếu thu chi'],
                ['payments.delete', 'Xóa phiếu thu chi'],
            ],
            'accounts' => [
                ['accounts.view', 'Xem tài khoản kế toán'],
                ['accounts.create', 'Thêm tài khoản'],
                ['accounts.edit', 'Sửa tài khoản'],
            ],
            'assets' => [
                ['assets.view', 'Xem tài sản cố định'],
                ['assets.create', 'Thêm / ghi sổ khấu hao'],
            ],
            'journal' => [
                ['journal.view', 'Xem nhật ký'],
                ['journal.create', 'Tạo bút toán thủ công'],
            ],
            'promotions' => [
                ['promotions.view', 'Xem chương trình khuyến mại'],
                ['promotions.create', 'Thêm khuyến mại'],
                ['promotions.edit', 'Sửa khuyến mại'],
                ['promotions.delete', 'Xóa khuyến mại'],
            ],
            'reports' => [
                ['reports.view', 'Xem báo cáo'],
                ['reports.view_profit', 'Xem lợi nhuận / lời lỗ'],
            ],
            'employees' => [
                ['employees.view', 'Xem nhân viên'],
                ['employees.create', 'Thêm nhân viên'],
                ['employees.edit', 'Sửa nhân viên'],
                ['employees.delete', 'Xóa nhân viên'],
            ],
            'roles' => [
                ['roles.view', 'Xem vai trò'],
                ['roles.create', 'Thêm vai trò'],
                ['roles.edit', 'Sửa vai trò'],
                ['roles.delete', 'Xóa vai trò'],
            ],
            'tours' => [
                ['tours.view', 'Xem tour du lịch (của mình)'],
                ['tours.view_all', 'Xem tour của tất cả nhân viên'],
                ['tours.create', 'Tạo / sửa tour'],
                ['tours.quote', 'Lên báo giá tour'],
                ['tours.confirm', 'Xác nhận / hoàn thành tour'],
                ['tours.cancel', 'Hủy tour'],
                ['tours.operate', 'Điều hành tour'],
                ['tours.settle', 'Quyết toán tour'],
                ['tours.payment_request', 'Lên lệnh thanh toán dịch vụ tour'],
                ['tours.payment_approve', 'Duyệt phiếu thanh toán dịch vụ tour'],
            ],
            'settings' => [
                ['settings.view', 'Xem cài đặt'],
                ['settings.edit', 'Sửa cài đặt'],
            ],
        ];

        foreach ($definitions as $module => $items) {
            foreach ($items as [$name, $displayName]) {
                Permission::updateOrCreate(
                    ['name' => $name],
                    ['display_name' => $displayName, 'module' => $module]
                );
            }
        }
    }
}
