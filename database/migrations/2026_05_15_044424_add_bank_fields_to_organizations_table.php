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
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('bank_id')->nullable()->after('website');
            $table->string('bank_account_name')->nullable()->after('bank_id');
            $table->string('bank_account_number', 50)->nullable()->after('bank_account_name');
            $table->foreign('bank_id')->references('id')->on('banks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['bank_id']);
            $table->dropColumn(['bank_id', 'bank_account_name', 'bank_account_number']);
        });
    }
};
