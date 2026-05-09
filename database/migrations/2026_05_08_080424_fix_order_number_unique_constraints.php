<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique('purchase_orders_order_number_unique');
            $table->unique(['organization_id', 'order_number']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique('sales_orders_order_number_unique');
            $table->unique(['organization_id', 'order_number']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_payment_number_unique');
            $table->unique(['organization_id', 'payment_number']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_entries_entry_number_unique');
            $table->unique(['organization_id', 'entry_number']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'order_number']);
            $table->unique('order_number');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'order_number']);
            $table->unique('order_number');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'payment_number']);
            $table->unique('payment_number');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'entry_number']);
            $table->unique('entry_number');
        });
    }
};
