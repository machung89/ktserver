<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Giá trị tồn theo sổ (perpetual): tích lũy quantity × unit_price của mọi giao dịch kho.
     * Khi tồn không âm, stock_value = quantity × avg_cost. Khi bán-trước-nhập-sau (tồn âm),
     * stock_value giữ đúng giá vốn đã ghi 632; chênh lệch với WAC chính là khoản cần kết chuyển.
     */
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->decimal('stock_value', 18, 2)->default(0)->after('avg_cost');
        });

        // Khởi tạo baseline: tồn hiện tại × giá bình quân (chênh lệch = 0 cho dữ liệu cũ)
        DB::statement('UPDATE inventories SET stock_value = ROUND(quantity * avg_cost, 2)');
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('stock_value');
        });
    }
};
