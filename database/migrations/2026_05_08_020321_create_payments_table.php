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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique()->comment('Số phiếu thu/chi');
            $table->string('type')->comment('receipt | payment');
            $table->foreignId('company_id')->constrained()->comment('Khách hàng / nhà cung cấp');
            $table->foreignId('account_id')->constrained()->comment('TK 111 hoặc 112');
            $table->nullableMorphs('reference');
            $table->date('payment_date');
            $table->decimal('amount', 18, 2);
            $table->string('payment_method')->comment('cash | bank_transfer');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
