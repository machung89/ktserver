<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'products', 'warehouses', 'companies', 'accounts',
        'inventories', 'inventory_transactions',
        'purchase_orders', 'sales_orders',
        'payments', 'journal_entries',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('organization_id')->nullable()->after('id')
                    ->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['organization_id']);
                $t->dropColumn('organization_id');
            });
        }
    }
};
