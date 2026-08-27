<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_dialogs', function (Blueprint $table) {
            $table->bigInteger('account_id')->index(); // Account owning the dialog list
            $table->bigInteger('peer_id')->index(); // Dialog target peer ID
            $table->bigInteger('top_message_id')->nullable();
            $table->integer('unread_count')->default(0);
            $table->integer('unread_mentions_count')->default(0);
            $table->bigInteger('pts')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->primary(['account_id', 'peer_id']);
        });

        Schema::create('telegram_account_entities', function (Blueprint $table) {
            $table->bigInteger('account_id')->index();
            $table->bigInteger('entity_id')->index(); // User, Chat, or Channel ID
            $table->string('relationship', 32); // 'contact', 'member', 'admin', 'creator', 'self'
            $table->timestamps();

            $table->primary(['account_id', 'entity_id', 'relationship']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_account_entities');
        Schema::dropIfExists('telegram_dialogs');
    }
};
