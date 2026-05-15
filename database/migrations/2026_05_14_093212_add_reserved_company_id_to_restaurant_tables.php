<?php

use App\Models\Company;
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
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->foreignId('reserved_company_id')->nullable()->after('reserved_phone')
                ->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropForeignIdFor(Company::class, 'reserved_company_id');
            $table->dropColumn('reserved_company_id');
        });
    }
};
