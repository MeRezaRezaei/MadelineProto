<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->bigInteger('id')->primary(); // Basic Group Chat ID
            $table->string('title', 255);
            $table->integer('participants_count')->default(0);
            $table->json('photo')->nullable();
            $table->json('raw_attributes')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_channels', function (Blueprint $table) {
            $table->bigInteger('id')->primary(); // Channel / Supergroup 64-bit ID
            $table->unsignedBigInteger('access_hash')->nullable();
            $table->string('title', 255);
            $table->string('username', 128)->nullable()->index();
            $table->integer('participants_count')->default(0);
            $table->boolean('is_broadcast')->default(false);
            $table->boolean('is_megagroup')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->json('photo')->nullable();
            $table->json('raw_attributes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_channels');
        Schema::dropIfExists('telegram_chats');
    }
};
