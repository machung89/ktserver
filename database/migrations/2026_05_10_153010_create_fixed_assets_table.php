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
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->string('code')->comment('Mã TSCĐ, e.g. TSCĐ-000001');
            $table->string('name')->comment('Tên tài sản');
            $table->text('description')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete()->comment('Nhà cung cấp');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete()->comment('TK thanh toán 111/112/331');
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete()->comment('TK chi phí khấu hao 641/642/627');
            $table->date('purchase_date');
            $table->date('depreciation_start_date')->comment('Ngày bắt đầu tính khấu hao');
            $table->decimal('cost', 18, 2)->comment('Nguyên giá');
            $table->decimal('residual_value', 18, 2)->default(0)->comment('Giá trị thanh lý ước tính');
            $table->unsignedSmallInteger('useful_life_years')->comment('Số năm sử dụng');
            $table->string('asset_account_code')->default('211');
            $table->string('accumulated_depreciation_account_code')->default('214');
            $table->string('status')->default('active')->comment('active | disposed');
            $table->unique(['organization_id', 'code']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
