<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trọng lượng sản phẩm tính bằng gram — dùng cho tích hợp vận chuyển (GHN...).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('weight')->default(0)->after('cost_price')->comment('Trọng lượng (gram)');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
