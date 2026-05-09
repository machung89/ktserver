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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Tên doanh nghiệp');
            $table->string('type')->default('both')->comment('customer | supplier | both');
            $table->string('tax_code')->nullable()->unique()->comment('Mã số thuế');
            $table->string('phone')->nullable()->comment('Số điện thoại');
            $table->string('email')->nullable()->comment('Email');
            $table->string('address')->nullable()->comment('Địa chỉ');
            $table->string('city')->nullable()->comment('Tỉnh/Thành phố');
            $table->string('representative')->nullable()->comment('Người đại diện');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
