<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('standard_price', 15, 2)->nullable()->after('price')
                ->comment('Giá chuẩn nhân viên được phép bán');
        });

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('standard_price', 18, 2)->nullable()->after('cost_price')
                ->comment('Giá chuẩn tại thời điểm bán');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('standard_total', 18, 2)->nullable()->after('total_amount')
                ->comment('Tổng tiền theo giá chuẩn');
            $table->decimal('employee_profit', 18, 2)->nullable()->after('standard_total')
                ->comment('Lời/lỗ nhân viên = total_amount - standard_total');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('standard_price');
        });

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn('standard_price');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['standard_total', 'employee_profit']);
        });
    }
};
