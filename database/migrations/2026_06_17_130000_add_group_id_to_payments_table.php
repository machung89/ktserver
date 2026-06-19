<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nhóm các phiếu sinh ra trong cùng một thao tác thu gộp (phiếu thu áp đơn + phiếu thu trước phần dư),
     * để khi xóa thì đảo toàn bộ nhóm — một thao tác = hủy tương ứng đầy đủ.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('group_id')->nullable()->after('payment_number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('group_id');
        });
    }
};
