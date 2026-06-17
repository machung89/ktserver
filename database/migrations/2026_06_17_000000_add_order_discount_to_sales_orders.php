<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chiết khấu cấp đơn hàng (ngoài chiết khấu từng dòng).
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('discount_type', 20)->nullable()->after('tax_amount');
            $table->decimal('discount_value', 18, 2)->default(0)->after('discount_type');
            $table->decimal('discount_amount', 18, 2)->default(0)->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount']);
        });
    }
};
