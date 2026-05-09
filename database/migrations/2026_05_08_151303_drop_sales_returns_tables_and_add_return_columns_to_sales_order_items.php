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
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');

        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->boolean('is_return')->default(false)->after('amount');
            $table->date('return_date')->nullable()->after('is_return');
            $table->string('return_note')->nullable()->after('return_date');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn(['is_return', 'return_date', 'return_note']);
        });
    }
};
