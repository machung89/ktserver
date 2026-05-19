<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('warehouse_exports')
            ->whereIn('status', ['draft', 'cancelled'])
            ->update(['status' => 'confirmed']);
    }

    public function down(): void {}
};
