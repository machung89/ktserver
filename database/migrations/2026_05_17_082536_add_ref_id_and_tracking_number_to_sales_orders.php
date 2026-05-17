<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('ref_id')->nullable()->after('order_number')->comment('Mã đơn từ sàn thương mại điện tử');
            $table->string('tracking_number')->nullable()->after('ref_id')->comment('Mã vận đơn');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['ref_id', 'tracking_number']);
        });
    }
};
