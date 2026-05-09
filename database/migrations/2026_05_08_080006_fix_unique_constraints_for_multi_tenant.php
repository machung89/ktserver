<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_code_unique');
            $table->unique(['organization_id', 'code']);
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique('warehouses_code_unique');
            $table->unique(['organization_id', 'code']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique('accounts_code_unique');
            $table->unique(['organization_id', 'code']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique('companies_tax_code_unique');
            $table->unique(['organization_id', 'tax_code']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'code']);
            $table->unique('code');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'code']);
            $table->unique('code');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'code']);
            $table->unique('code');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'tax_code']);
            $table->unique('tax_code');
        });
    }
};
