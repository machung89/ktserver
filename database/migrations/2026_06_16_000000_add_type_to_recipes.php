<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phân biệt định mức (recipe: gồm nguyên liệu) và combo (gồm sản phẩm bán lẻ).
     * Cả hai cùng dùng engine trừ kho thành phần khi bán.
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->enum('type', ['recipe', 'combo'])->default('recipe')->after('product_id');
            $table->index(['organization_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'type']);
            $table->dropColumn('type');
        });
    }
};
