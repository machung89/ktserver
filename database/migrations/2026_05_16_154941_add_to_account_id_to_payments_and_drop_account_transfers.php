<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('to_account_id')->nullable()->after('account_id')->constrained('accounts');
        });

        Schema::dropIfExists('account_transfers');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['to_account_id']);
            $table->dropColumn('to_account_id');
        });
    }
};
