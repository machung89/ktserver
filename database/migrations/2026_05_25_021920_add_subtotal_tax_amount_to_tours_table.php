<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->decimal('subtotal', 15, 2)->default(0)->after('child_price');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('subtotal');
        });

        // Backfill: subtotal = num_adults*unit_price + num_children*child_price
        //           tax_amount = subtotal * vat_rate / 100
        //           total_amount = subtotal + tax_amount + extra_revenues_total
        DB::statement('
            UPDATE tours SET
                subtotal    = num_adults * unit_price + num_children * child_price,
                tax_amount  = (num_adults * unit_price + num_children * child_price) * vat_rate / 100,
                total_amount = (num_adults * unit_price + num_children * child_price) * (1 + vat_rate / 100) + extra_revenues_total
        ');
    }

    public function down(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'tax_amount']);
        });
    }
};
