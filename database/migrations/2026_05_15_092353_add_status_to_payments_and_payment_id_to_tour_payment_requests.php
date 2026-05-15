<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // draft = chờ giám đốc duyệt, approved = đã duyệt + có bút toán
            $table->string('status')->default('approved')->after('is_advance');
        });

        Schema::table('tour_payment_requests', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete()->after('reject_reason');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('tour_payment_requests', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });
    }
};
