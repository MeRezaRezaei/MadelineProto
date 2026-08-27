<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Bot Credentials (for Mini App HMAC authentication & Bot API)
    |--------------------------------------------------------------------------
    */
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),

    /*
    |--------------------------------------------------------------------------
    | Default MTProto Client Settings
    |--------------------------------------------------------------------------
    */
    'api_id' => env('TELEGRAM_API_ID'),
    'api_hash' => env('TELEGRAM_API_HASH'),

    /*
    |--------------------------------------------------------------------------
    | Redis Streams & Command Queues
    |--------------------------------------------------------------------------
    */
    'redis_connection' => env('TELEGRAM_REDIS_CONNECTION', 'default'),
    'update_stream' => env('TELEGRAM_UPDATE_STREAM', 'tg:stream:updates'),
    'command_queue_prefix' => env('TELEGRAM_COMMAND_QUEUE_PREFIX', 'tg:queue:commands:'),
];
