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
        Schema::create('banks', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('code', 20);
            $table->string('bin', 10)->unique();
            $table->string('short_name', 100);
            $table->string('logo')->nullable();
            $table->string('swift_code', 20)->nullable();
            $table->boolean('transfer_supported')->default(false);
            $table->boolean('lookup_supported')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
