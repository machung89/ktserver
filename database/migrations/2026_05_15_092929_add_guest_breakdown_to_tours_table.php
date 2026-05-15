<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            // num_adults / num_children thay thế num_guests (num_guests = num_adults + num_children)
            $table->unsignedInteger('num_adults')->default(1)->after('num_guests');
            $table->unsignedInteger('num_children')->default(0)->after('num_adults');
            // child_price mặc định 70% unit_price (đơn giá người lớn)
            $table->decimal('child_price', 15, 2)->default(0)->after('unit_price');
        });

        // Migrate existing data: toàn bộ khách hiện tại là người lớn
        DB::statement('UPDATE tours SET num_adults = num_guests, num_children = 0, child_price = ROUND(unit_price * 0.7, 0)');
    }

    public function down(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->dropColumn(['num_adults', 'num_children', 'child_price']);
        });
    }
};
