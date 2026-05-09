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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Mã sản phẩm');
            $table->string('name')->comment('Tên sản phẩm');
            $table->text('description')->nullable()->comment('Mô tả');
            $table->string('unit')->comment('Đơn vị tính (cái, kg, hộp, ...)');
            $table->string('category')->nullable()->comment('Danh mục');
            $table->decimal('price', 15, 2)->default(0)->comment('Giá bán');
            $table->decimal('cost_price', 15, 2)->default(0)->comment('Giá vốn');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
