<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trạng thái xuất hóa đơn cho đơn bán: chưa xuất / đề xuất / đã xuất.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('invoice_status', 20)->default('not_issued')->after('payment_status');
            $table->index(['organization_id', 'invoice_status'], 'so_org_invoice_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('so_org_invoice_status_idx');
            $table->dropColumn('invoice_status');
        });
    }
};
