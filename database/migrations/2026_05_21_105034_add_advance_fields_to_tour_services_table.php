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
            $table->decimal('advance_amount', 15, 2)->default(0)->after('paid_amount');
            $table->foreignId('guide_id')->nullable()->after('advance_amount')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tour_services', function (Blueprint $table) {
            $table->dropForeign(['guide_id']);
            $table->dropColumn(['advance_amount', 'guide_id']);
        });
    }
};
