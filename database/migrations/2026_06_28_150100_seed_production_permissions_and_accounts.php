<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string}> */
    private array $permissions = [
        ['production.view', 'Xem lệnh sản xuất'],
        ['production.create', 'Tạo lệnh sản xuất'],
        ['production.edit', 'Sửa lệnh sản xuất'],
        ['production.complete', 'Hoàn thành (nhập kho thành phẩm)'],
        ['production.cancel', 'Hủy lệnh sản xuất'],
        ['production.delete', 'Xóa lệnh sản xuất'],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->permissions as [$name, $display]) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['display_name' => $display, 'module' => 'production', 'updated_at' => $now, 'created_at' => $now],
            );
        }

        // Bật các tài khoản sản xuất cho mọi tổ chức hiện có (để hiển thị/báo cáo)
        DB::table('accounts')->whereIn('code', ['152', '154', '155', '621', '622', '627'])->update(['is_active' => true]);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', array_column($this->permissions, 0))->delete();
    }
};
