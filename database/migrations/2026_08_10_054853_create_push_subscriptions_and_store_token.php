<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Token cửa hàng công khai (để khách truy cập/đăng ký nhận thông báo mà không cần đăng nhập)
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('id');
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64);   // sha256(endpoint) — để đặt unique (endpoint quá dài không index trực tiếp được)
            $table->string('public_key');          // p256dh
            $table->string('auth_token');          // auth
            $table->string('content_encoding')->default('aesgcm');
            $table->timestamps();

            $table->unique(['organization_id', 'endpoint_hash'], 'push_org_endpoint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
