<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->whereNull('standard_price')->update(['standard_price' => 0]);
        DB::table('sales_order_items')->whereNull('standard_price')->update(['standard_price' => 0]);

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('standard_price', 15, 2)->default(0)->change();
        });

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('standard_price', 18, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('standard_price', 15, 2)->nullable()->default(null)->change();
        });

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('standard_price', 18, 2)->nullable()->default(null)->change();
        });
    }
};
