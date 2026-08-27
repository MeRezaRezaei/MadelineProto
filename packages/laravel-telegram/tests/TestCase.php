<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Tests;

use Danog\LaravelTelegram\TelegramServiceProvider;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capsule = new Capsule();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $this->capsule->setEventDispatcher(new Dispatcher(new Container()));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->runMigrations();
    }

    protected function runMigrations(): void
    {
        $schema = $this->capsule->schema();

        // 1. telegram_accounts
        $schema->create('telegram_accounts', function ($table) {
            $table->bigInteger('id')->primary();
            $table->string('user_id')->nullable()->index();
            $table->string('phone', 32)->nullable()->index();
            $table->bigInteger('api_id');
            $table->string('api_hash', 64);
            $table->integer('dc_id')->default(2);
            $table->string('auth_state', 32)->default('unauthorized')->index();
            $table->text('session_key_encrypted')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // 2. telegram_users
        $schema->create('telegram_users', function ($table) {
            $table->bigInteger('id')->primary();
            $table->unsignedBigInteger('access_hash')->nullable();
            $table->string('first_name', 255)->nullable();
            $table->string('last_name', 255)->nullable();
            $table->string('username', 128)->nullable()->index();
            $table->string('phone', 32)->nullable()->index();
            $table->boolean('is_bot')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_premium')->default(false);
            $table->json('status')->nullable();
            $table->json('photo')->nullable();
            $table->json('raw_attributes')->nullable();
            $table->timestamps();
        });

        // 3. telegram_chats & telegram_channels
        $schema->create('telegram_chats', function ($table) {
            $table->bigInteger('id')->primary();
            $table->string('title', 255);
            $table->integer('participants_count')->default(0);
            $table->json('photo')->nullable();
            $table->json('raw_attributes')->nullable();
            $table->timestamps();
        });

        $schema->create('telegram_channels', function ($table) {
            $table->bigInteger('id')->primary();
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

        // 4. telegram_peers
        $schema->create('telegram_peers', function ($table) {
            $table->bigInteger('peer_id')->primary();
            $table->string('type', 16);
            $table->string('username', 128)->nullable()->index();
            $table->string('phone', 32)->nullable()->index();
            $table->timestamps();
        });

        // 5. telegram_messages
        $schema->create('telegram_messages', function ($table) {
            $table->bigInteger('id');
            $table->bigInteger('peer_id')->index();
            $table->bigInteger('from_id')->nullable()->index();
            $table->timestamp('date')->index();
            $table->text('message')->nullable();
            $table->string('media_type', 32)->nullable()->index();
            $table->string('media_hash', 64)->nullable()->index();
            $table->json('media_meta')->nullable();
            $table->bigInteger('reply_to_msg_id')->nullable();
            $table->bigInteger('reply_to_peer_id')->nullable();
            $table->json('entities')->nullable();
            $table->integer('views')->nullable();
            $table->integer('forwards')->nullable();
            $table->boolean('is_outgoing')->default(false);
            $table->json('raw_attributes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->primary(['peer_id', 'id']);
        });

        // 6. telegram_dialogs & telegram_account_entities
        $schema->create('telegram_dialogs', function ($table) {
            $table->bigInteger('account_id')->index();
            $table->bigInteger('peer_id')->index();
            $table->bigInteger('top_message_id')->nullable();
            $table->integer('unread_count')->default(0);
            $table->integer('unread_mentions_count')->default(0);
            $table->bigInteger('pts')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->primary(['account_id', 'peer_id']);
        });

        $schema->create('telegram_account_entities', function ($table) {
            $table->bigInteger('account_id')->index();
            $table->bigInteger('entity_id')->index();
            $table->string('relationship', 32);
            $table->timestamps();

            $table->primary(['account_id', 'entity_id', 'relationship']);
        });
    }
}
