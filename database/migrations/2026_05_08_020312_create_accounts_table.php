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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Mã tài khoản VAS (111, 112, 131, ...)');
            $table->string('name')->comment('Tên tài khoản');
            $table->string('type')->comment('asset | liability | equity | revenue | expense');
            $table->string('parent_code')->nullable()->comment('Mã tài khoản cha');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
