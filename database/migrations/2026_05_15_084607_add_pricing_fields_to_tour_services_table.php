<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_services', function (Blueprint $table) {
            $table->decimal('unit_price', 15, 2)->default(0)->after('supplier_id');
            $table->unsignedInteger('quantity')->default(1)->after('unit_price');
            $table->unsignedInteger('days')->default(1)->after('quantity');
            $table->decimal('paid_amount', 15, 2)->default(0)->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('tour_services', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'quantity', 'days', 'paid_amount']);
        });
    }
};
