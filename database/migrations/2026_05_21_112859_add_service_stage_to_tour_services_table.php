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
        Schema::table('tour_services', function (Blueprint $table) {
            $table->string('service_stage', 20)->default('quote')->after('tour_id');
        });
    }

    public function down(): void
    {
        Schema::table('tour_services', function (Blueprint $table) {
            $table->dropColumn('service_stage');
        });
    }
};
