<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clear shared (org-less) roles and their pivots before adding the column
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('role_user')->truncate();
        DB::table('permission_role')->truncate();
        DB::table('roles')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->dropUnique('roles_name_unique');
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'name']);
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
            $table->unique('name');
        });
    }
};
