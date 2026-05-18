<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('standard_total', 18, 2)->nullable()->default(null)->change();
            $table->decimal('employee_profit', 18, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('standard_total', 18, 2)->nullable()->change();
            $table->decimal('employee_profit', 18, 2)->nullable()->change();
        });
    }
};
