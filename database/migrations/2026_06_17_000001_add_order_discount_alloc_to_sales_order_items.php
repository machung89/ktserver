<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phần chiết khấu cấp đơn được phân bổ cho từng dòng (snapshot lúc xác nhận).
     * Báo cáo dùng (amount - order_discount_alloc) làm doanh thu thuần — một nguồn sự thật.
     */
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('order_discount_alloc', 18, 2)->default(0)->after('amount');
        });

        // Backfill cho dữ liệu cũ (đa số discount_amount = 0 nên alloc = 0)
        DB::statement('
            UPDATE sales_order_items soi
            JOIN sales_orders so ON so.id = soi.sales_order_id
            SET soi.order_discount_alloc = ROUND(so.discount_amount * soi.amount / NULLIF(so.subtotal, 0), 2)
            WHERE so.discount_amount > 0 AND so.subtotal > 0
        ');
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn('order_discount_alloc');
        });
    }
};
