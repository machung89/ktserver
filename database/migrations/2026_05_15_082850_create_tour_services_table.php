<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tour_id');
            $table->string('service_type', 30)->default('other');
            $table->string('name');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tour_id')->references('id')->on('tours')->cascadeOnDelete();
            $table->foreign('supplier_id')->references('id')->on('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_services');
    }
};
