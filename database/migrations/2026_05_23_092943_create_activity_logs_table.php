<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->unsignedBigInteger('tour_id')->nullable()->index();
            $table->string('action', 50);
            $table->string('description');
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->foreign('causer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('tour_id')->references('id')->on('tours')->cascadeOnDelete();
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
