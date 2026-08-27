# Laravel Telegram (MTProto 2.0 & Bot API Client)

**Author:** MeRezaRezaei  
**License:** MIT  

A modern, high-performance, stateless **MTProto 2.0 and Bot API client for Laravel**.

Designed strictly as a **protocol client** — meaning **zero database bloat, zero forced schema migrations, and zero filesystem locks (`.safe.php`)**. Your Laravel application retains 100% control over session storage (PostgreSQL, Redis, Encrypted Strings), queues, and business workflows.

---

## ✨ Features

- **Dual-Account Support:** 
  - **User Accounts (`Telegram::user(...)`):** Full MTProto 2.0 user sessions with phone, SMS code, and 2FA SRP cloud password support.
  - **Bot Accounts (`Telegram::bot(...)`):** Bot API & MTProto bot authorization.
- **Dynamic Multi-Tenant & Single-Tenant Hybrid:** Pass `api_id`, `api_hash`, `auth_key`, and proxy settings dynamically at runtime per account, or fall back to `.env` defaults.
- **Pure MIT Cryptography:** Hand-crafted AES-256-IGE packet cipher and 2FA SRP cloud password calculations.
- **TL Binary Serialization:** High-performance Type Language packer and unpacker.
- **SOCKS5 & HTTP Proxy Support:** Route connections through Tor, corporate proxies, or rotating proxies.
- **Telegram Mini App Security:** Built-in HMAC-SHA256 validator middleware for Telegram Web Apps & Mini Apps (`initData`).
- **Laravel 10 / 11 / 12 / 13 Ready:** PSR-4 autoloading, ServiceProvider auto-discovery, and Facades.

---

## 📦 Installation

```bash
composer require merezarezaei/laravel-telegram
```

Publish configuration:
```bash
php artisan vendor:publish --tag="telegram-config"
```

---

## 🚀 Quickstart

### 1. Multi-Tenant User Account (Runtime Credentials)

```php
use MeRezaRezaei\LaravelTelegram\Facades\Telegram;

// Bind to an authenticated account (decrypted AuthKey from your DB)
$userClient = Telegram::user(
    accountId: $account->telegram_id,
    authKey: decrypt($account->encrypted_auth_key),
    dcId: $account->dc_id ?? 2,
    apiId: $account->api_id,       // Dynamic runtime API ID
    apiHash: $account->api_hash    // Dynamic runtime API Hash
);

// Send message
$result = $userClient->sendMessage(
    peer: '@durov',
    text: 'Hello from multi-tenant user!'
);
```

### 2. Single-Tenant User Account (Default `.env` Credentials)

```php
// Uses default TELEGRAM_API_ID and TELEGRAM_API_HASH from config/telegram.php
$userClient = Telegram::user(
    accountId: 123456789,
    authKey: $rawAuthKey
);
```

### 3. Bot Account (Dynamic or Default Token)

```php
// Dynamic Bot Token
$bot = Telegram::bot('123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11');
$bot->sendMessage('@mychannel', 'Announcement from Bot!');

// Or default bot token from .env
$defaultBot = Telegram::bot();
```

### 4. Executing Raw MTProto RPC Methods

```php
// Call any Telegram method defined in the Layer 227+ schema
$userInfo = $userClient->call('users.getFullUser', [
    'id' => ['_' => 'inputUser', 'user_id' => 123456, 'access_hash' => 0],
]);
```

### 5. SOCKS5 Proxy Configuration

```php
$userClient = Telegram::user(
    accountId: 123456789,
    authKey: $rawAuthKey,
    proxyConfig: [
        'type' => 'socks5',
        'host' => '127.0.0.1',
        'port' => 9050, // Tor or custom proxy
        'username' => 'proxy_user',
        'password' => 'proxy_pass',
    ]
);
```

### 6. Protecting Telegram Mini App Routes

Protect your Telegram Mini App backend API with verified HMAC middleware:

```php
Route::middleware('tg.miniapp')->group(function () {
    Route::post('/api/miniapp/user', function (Request $request) {
        $tgUser = $request->attributes->get('telegram_user');
        return response()->json(['user' => $tgUser]);
    });
});
```

---

## 🛡️ License

Released under the **MIT License**. Copyright (c) 2026 MeRezaRezaei.
