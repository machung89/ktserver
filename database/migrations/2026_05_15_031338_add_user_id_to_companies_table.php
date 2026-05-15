<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->unique()->after('organization_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // Backfill: create an employee company for every existing user that doesn't have one
        $users = DB::table('users')->get(['id', 'organization_id', 'name', 'email', 'phone']);

        foreach ($users as $user) {
            DB::table('companies')->insert([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'name' => $user->name,
                'type' => 'employee',
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
