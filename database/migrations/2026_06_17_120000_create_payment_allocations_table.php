<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phân bổ 1 phiếu thu cho nhiều đơn (mô hình open-item: 1 chứng từ tiền, nhiều mục được tất toán).
     * Giúp thu gộp nhiều đơn chỉ tạo 1 phiếu thu + 1 bút toán, dễ tra soát.
     */
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index(['sales_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
