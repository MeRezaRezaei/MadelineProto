# Clean-Room MIT Laravel Telegram Specification

**License:** MIT  
**Author:** AI Agent (Antigravity) & Lead Engineer  
**Scope:** Standalone, pure stateless MTProto 2.0 & Bot API Client for Laravel (Clean-Room Implementation)

---

## 1. Core Principles

1. **Clean-Room & 100% MIT:** Built from Telegram's open MTProto 2.0 specifications without copying legacy AGPLv3 codebase.
2. **Dynamic Multi-Tenant & Single-Tenant Hybrid:**
   - **Multi-Tenant Mode:** Developers can pass `api_id`, `api_hash`, `auth_key`, and proxy settings dynamically at runtime per account.
   - **Single-Tenant Fallback:** If runtime credentials are omitted, it gracefully falls back to `.env` / `config/telegram.php` defaults.
3. **Dual Account Types:**
   - **User Accounts (`Telegram::user(...)`):** Full MTProto 2.0 user session with phone, code, and 2FA SRP cloud password.
   - **Bot Accounts (`Telegram::bot(...)`):** Supports both HTTP Bot API and MTProto bot authorization (`auth.importBotAuthorization`).
4. **Zero Database Bloat / Zero File Locks:** 100% stateless protocol client. Storage of sessions, users, and tokens is owned by the developer's Laravel application.

---

## 2. API Interface Design

```php
use Danog\LaravelTelegram\Facades\Telegram;

// 1. Multi-Tenant User Account (Runtime API credentials & AuthKey from DB)
$userClient = Telegram::user(
    accountId: $account->telegram_id,
    authKey: decrypt($account->encrypted_auth_key),
    dcId: $account->dc_id ?? 2,
    apiId: $account->api_id,
    apiHash: $account->api_hash
);
$userClient->sendMessage('@durov', 'Hello from multi-tenant user!');

// 2. Single-Tenant User Account (Falls back to .env defaults)
$singleUser = Telegram::user(
    accountId: 123456789,
    authKey: $rawAuthKey
);

// 3. Bot Account (Runtime Bot Token)
$bot = Telegram::bot('123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11');
$bot->sendMessage('@mychannel', 'Hello from Bot!');
```
