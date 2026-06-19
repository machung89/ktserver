<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DefaultAccountsService
{
    /**
     * Các tài khoản cơ bản thường dùng — mặc định BẬT khi đăng ký tổ chức mới.
     * Các tài khoản TT200 còn lại vẫn được tạo nhưng để TẮT; người dùng bật khi cần.
     * (Bút toán tự động vẫn dùng được vì truy theo mã, không lọc is_active.)
     *
     * @var array<int, string>
     */
    private const BASIC_ACTIVE_CODES = [
        // Tiền
        '111', '1111', '1112', '112', '1121', '1122',
        // Phải thu / thuế GTGT đầu vào / tạm ứng
        '131', '133', '1331', '138', '1388', '141',
        // Hàng tồn kho
        '152', '153', '155', '156',
        // Chi phí trả trước / TSCĐ
        '242', '211', '2111', '214', '2141',
        // Phải trả / thuế / lương
        '331', '333', '3331', '33311', '3334', '334', '338', '3382', '3383', '3384',
        // Vốn chủ sở hữu
        '411', '4111', '421', '4211',
        // Doanh thu / thu nhập
        '511', '515', '521', '711',
        // Giá vốn / chi phí
        '632', '635', '641', '642', '811', '821',
        // Xác định kết quả kinh doanh
        '911',
    ];

    /**
     * Copy the standard VAS chart of accounts into the given organization.
     * Uses INSERT IGNORE so it is safe to call multiple times.
     */
    public function seedForOrganization(int $organizationId): void
    {
        $templates = DB::table('system_accounts')->orderBy('sort_order')->get();

        if ($templates->isEmpty()) {
            return;
        }

        $now = now();
        $basic = array_flip(self::BASIC_ACTIVE_CODES);

        $rows = $templates->map(fn ($t) => [
            'code' => $t->code,
            'name' => $t->name,
            'type' => $t->type,
            'parent_code' => $t->parent_code,
            'organization_id' => $organizationId,
            'is_active' => isset($basic[$t->code]),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('accounts')->insertOrIgnore($chunk);
        }
    }
}
