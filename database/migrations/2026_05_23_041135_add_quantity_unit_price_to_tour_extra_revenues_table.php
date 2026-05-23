<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_extra_revenues', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->after('name');
            $table->decimal('unit_price', 15, 2)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('tour_extra_revenues', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'unit_price']);
        });
    }
};
