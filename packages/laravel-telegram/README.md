# Laravel Telegram (MTProto Client)

A modern, high-performance, stateless **MTProto 2.0 Telegram API client for Laravel**.

Unlike legacy clients, this package is designed strictly as a **protocol client** — meaning **zero database bloat, zero filesystem locks (`.safe.php`), and zero custom framework overhead**. Your Laravel application controls session storage (PostgreSQL, Redis, Encrypted Strings), queues, and business workflows.

---

## ✨ Features

- **Stateless MTProto 2.0 Client:** Pass user credentials and AuthKeys per instance or call.
- **Pure Cryptography:** Hand-crafted AES-256-IGE packet cipher and 2FA SRP cloud password calculations.
- **TL Binary Serialization:** High-performance Type Language packer and unpacker.
- **SOCKS5 & HTTP Proxy Support:** Route connections through Tor, corporate proxies, or rotating proxies.
- **Telegram Mini App Security:** Built-in HMAC-SHA256 validator middleware for Telegram Web Apps & Mini Apps (`initData`).
- **Laravel 10 / 11 / 12 / 13 Ready:** PSR-4 autoloading, ServiceProvider auto-discovery, and Facades.

---

## 📦 Installation

```bash
composer require yourname/laravel-telegram
```

Publish configuration:
```bash
php artisan vendor:publish --tag="telegram-config"
```

---

## 🚀 Quickstart

### 1. Sending a Message (Facade)

```php
use Danog\LaravelTelegram\Facades\Telegram;

// Bind to an authenticated account (decrypted AuthKey from your DB)
$client = Telegram::forAccount(
    accountId: $user->telegram_id,
    authKey: decrypt($user->telegram_auth_key),
    dcId: $user->telegram_dc_id ?? 2
);

// Send message
$result = $client->sendMessage(
    peer: '@durov',
    text: 'Hello from Laravel Telegram!'
);
```

### 2. Executing Any Raw MTProto Method

```php
// Call any Telegram method defined in the Layer 227+ schema
$userInfo = $client->call('users.getFullUser', [
    'id' => ['_' => 'inputUser', 'user_id' => 123456, 'access_hash' => 0],
]);
```

### 3. SOCKS5 Proxy Configuration

```php
$client->setProxy([
    'type' => 'socks5',
    'host' => '127.0.0.1',
    'port' => 9050, // Tor or custom proxy
    'username' => 'proxy_user',
    'password' => 'proxy_pass',
]);
```

### 4. Protecting Telegram Mini App Routes

Protect your Telegram Mini App backend API with the verified HMAC middleware:

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

Released under the **MIT License**.
