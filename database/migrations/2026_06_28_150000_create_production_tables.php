<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('production_number')->comment('Số lệnh sản xuất');
            $table->foreignId('product_id')->constrained()->comment('Thành phẩm');
            $table->foreignId('warehouse_id')->constrained()->comment('Kho nhập thành phẩm');
            $table->decimal('quantity', 18, 4)->default(0)->comment('Số lượng sản xuất');
            $table->string('status')->default('draft')->comment('draft | completed | cancelled');
            $table->decimal('material_cost', 18, 2)->default(0)->comment('Chi phí NVL (621)');
            $table->decimal('labor_cost', 18, 2)->default(0)->comment('Chi phí nhân công (622)');
            $table->decimal('overhead_cost', 18, 2)->default(0)->comment('Chi phí SX chung (627)');
            $table->decimal('total_cost', 18, 2)->default(0)->comment('Tổng giá thành (154)');
            $table->decimal('unit_cost', 18, 2)->default(0)->comment('Giá thành đơn vị');
            $table->date('production_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'production_number']);
            $table->index(['organization_id', 'status', 'production_date'], 'prod_org_status_date_idx');
        });

        Schema::create('production_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->comment('Nguyên vật liệu');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 2)->default(0)->comment('Giá vốn BQ tại thời điểm sản xuất');
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('production_order_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->string('type')->comment('labor | overhead');
            $table->string('name');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('credit_account_code')->comment('TK đối ứng (334/331/111/112...)');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_costs');
        Schema::dropIfExists('production_order_materials');
        Schema::dropIfExists('production_orders');
    }
};
