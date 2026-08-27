<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->bigInteger('id')->primary(); // Telegram User ID of the account owner
            $table->uuid('user_id')->nullable()->index(); // Platform owner user UUID (if multi-tenant)
            $table->string('phone', 32)->nullable()->index();
            $table->bigInteger('api_id');
            $table->string('api_hash', 64);
            $table->integer('dc_id')->default(2);
            $table->string('auth_state', 32)->default('unauthorized')->index(); // pending_code, pending_2fa, active, revoked
            $table->text('session_key_encrypted')->nullable(); // Encrypted MTProto AuthKey / session string
            $table->json('settings')->nullable(); // Account-specific settings
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_accounts');
    }
};
