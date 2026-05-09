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
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number')->unique()->comment('Số bút toán');
            $table->date('entry_date')->comment('Ngày hạch toán');
            $table->nullableMorphs('reference');
            $table->string('description')->nullable()->comment('Diễn giải');
            $table->boolean('is_posted')->default(false)->comment('Đã ghi sổ');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
