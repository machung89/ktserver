<?php

use App\Models\RestaurantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('restaurant_table_id')
                ->nullable()
                ->after('company_id')
                ->constrained('restaurant_tables')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeignIdFor(RestaurantTable::class);
            $table->dropColumn('restaurant_table_id');
        });
    }
};
