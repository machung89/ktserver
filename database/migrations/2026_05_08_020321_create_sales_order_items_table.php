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
        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('warehouse_id')->constrained()->comment('Xuất từ kho');
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('cost_price', 18, 2)->default(0)->comment('Giá vốn tại thời điểm bán');
            $table->decimal('tax_rate', 5, 2)->default(0)->comment('% thuế VAT');
            $table->decimal('amount', 18, 2)->comment('Thành tiền chưa thuế');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
    }
};
