<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['sales_orders', 'purchase_orders'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('payment_status')->default('unpaid')->after('total_amount');
                $t->decimal('paid_amount', 18, 2)->default(0)->after('payment_status');
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_orders', 'purchase_orders'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['payment_status', 'paid_amount']);
            });
        }
    }
};
