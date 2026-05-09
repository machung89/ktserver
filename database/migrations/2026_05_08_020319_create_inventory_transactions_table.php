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
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type')->comment('purchase_receipt | sales_delivery | adjustment');
            $table->foreignId('warehouse_id')->constrained();
            $table->nullableMorphs('reference');
            $table->date('transaction_date');
            $table->string('description')->nullable();
            $table->boolean('is_posted')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
