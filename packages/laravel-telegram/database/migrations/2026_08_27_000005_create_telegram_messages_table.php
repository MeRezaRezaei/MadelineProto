<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->bigInteger('id'); // Message ID in chat
            $table->bigInteger('peer_id')->index(); // Chat / Channel / User ID
            $table->bigInteger('from_id')->nullable()->index(); // Author ID
            $table->timestamp('date')->index();
            $table->text('message')->nullable();
            $table->string('media_type', 32)->nullable()->index();
            $table->string('media_hash', 64)->nullable()->index(); // SHA-256 for file deduplication
            $table->json('media_meta')->nullable(); // File references, sizes, dimensions, mime
            $table->bigInteger('reply_to_msg_id')->nullable();
            $table->bigInteger('reply_to_peer_id')->nullable();
            $table->json('entities')->nullable(); // TL MessageEntity array (bold, links, code, mentions)
            $table->integer('views')->nullable();
            $table->integer('forwards')->nullable();
            $table->boolean('is_outgoing')->default(false);
            $table->json('raw_attributes')->nullable();
            $table->softDeletes(); // deleted_at: Message is NEVER destroyed on Telegram delete updates
            $table->timestamps();

            $table->primary(['peer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
