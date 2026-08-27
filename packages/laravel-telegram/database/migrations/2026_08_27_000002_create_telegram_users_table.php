<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->bigInteger('id')->primary(); // Telegram User ID (verbatim 64-bit PK)
            $table->unsignedBigInteger('access_hash')->nullable();
            $table->string('first_name', 255)->nullable();
            $table->string('last_name', 255)->nullable();
            $table->string('username', 128)->nullable()->index();
            $table->string('phone', 32)->nullable()->index();
            $table->boolean('is_bot')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_premium')->default(false);
            $table->json('status')->nullable(); // UserStatus TL structure
            $table->json('photo')->nullable(); // Profile photo metadata
            $table->json('raw_attributes')->nullable(); // Forward compatibility for new TL layer attributes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};
