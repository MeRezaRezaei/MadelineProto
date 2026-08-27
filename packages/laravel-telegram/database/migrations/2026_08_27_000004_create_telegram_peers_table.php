<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_peers', function (Blueprint $table) {
            $table->bigInteger('peer_id')->primary(); // Unified Peer ID
            $table->string('type', 16); // 'user', 'chat', 'channel'
            $table->string('username', 128)->nullable()->index();
            $table->string('phone', 32)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_peers');
    }
};
