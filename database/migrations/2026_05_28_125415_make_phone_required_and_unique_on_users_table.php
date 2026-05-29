<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fill existing NULL / empty phones with a unique placeholder so NOT NULL can be applied
        DB::statement("UPDATE users SET phone = CONCAT('user_', id) WHERE phone IS NULL OR phone = ''");

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->string('phone')->nullable()->change();
        });
    }
};
