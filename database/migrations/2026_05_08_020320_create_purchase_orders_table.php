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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique()->comment('Số phiếu nhập');
            $table->foreignId('company_id')->constrained()->comment('Nhà cung cấp');
            $table->foreignId('warehouse_id')->constrained()->comment('Nhập vào kho');
            $table->date('order_date');
            $table->date('expected_date')->nullable()->comment('Ngày giao dự kiến');
            $table->string('status')->default('draft')->comment('draft | confirmed | completed | cancelled');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
