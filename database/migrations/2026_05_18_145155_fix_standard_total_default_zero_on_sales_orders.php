<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales_orders')->whereNull('standard_total')->update(['standard_total' => 0]);
        DB::table('sales_orders')->whereNull('employee_profit')->update(['employee_profit' => 0]);

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('standard_total', 18, 2)->default(0)->change();
            $table->decimal('employee_profit', 18, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('standard_total', 18, 2)->nullable()->default(null)->change();
            $table->decimal('employee_profit', 18, 2)->nullable()->default(null)->change();
        });
    }
};
