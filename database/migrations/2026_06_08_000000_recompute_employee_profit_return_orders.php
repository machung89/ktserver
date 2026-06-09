<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tính lại employee_profit cho mọi đơn có standard_total khác 0.
     * Trước đây điều kiện `standard_total > 0` khiến đơn trả hàng (standard_total âm)
     * luôn bị ép employee_profit = 0, không đảo ngược được lời/lỗ của đơn gốc.
     */
    public function up(): void
    {
        DB::table('sales_orders')
            ->whereRaw('ABS(standard_total) > 0.001')
            ->update([
                'employee_profit' => DB::raw('total_amount - standard_total'),
            ]);
    }

    public function down(): void
    {
        // Không thể khôi phục chính xác giá trị cũ.
    }
};
