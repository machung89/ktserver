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
        Schema::table('tour_payment_requests', function (Blueprint $table) {
            $table->dropForeign(['tour_service_id']);
            $table->unsignedBigInteger('tour_service_id')->nullable()->change();
            $table->foreign('tour_service_id')->references('id')->on('tour_services')->nullOnDelete();
            $table->string('request_type', 20)->default('service')->after('tour_id');
        });
    }

    public function down(): void
    {
        Schema::table('tour_payment_requests', function (Blueprint $table) {
            $table->dropForeign(['tour_service_id']);
            $table->dropColumn('request_type');
            $table->unsignedBigInteger('tour_service_id')->nullable(false)->change();
            $table->foreign('tour_service_id')->references('id')->on('tour_services')->cascadeOnDelete();
        });
    }
};
