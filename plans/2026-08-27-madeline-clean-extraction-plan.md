# Separation Plan: MadelineProto Clean Extraction to Laravel Telegram

**Goal:** Extract the pure MTProto 2.0 protocol engine from MadelineProto, remove all "mini-OS" framework clutter, and bundle it with the native Laravel Telegram platform into a clean, testable package ready for standalone repository release.

---

## 1. Inventory: What to Keep vs. What to Remove

### ✅ What We Keep (The Pure MTProto Engine)
1. **TL (Type Language) Binary Engine:**
   - `src/TL_telegram_v227.tl` (Layer 227 MTProto schema)
   - `src/TL_mtproto_v1.tl` (MTProto transport handshake schema)
   - `src/TL/TL.php` & `src/TL/TLParser.php` (Binary packing/unpacking)
   - `tools/build_tl.php` & `tools/TL/Builder.php` (Ahead-of-time TL compiler)
2. **MTProto 2.0 Cryptography:**
   - `src/RSA.php` (Telegram public key encryption)
   - `src/MTProtoTools/Crypt.php` (Diffie-Hellman math & prime validation)
   - `src/MTProtoTools/Crypt/IGE.php` (AES-256-IGE packet cipher)
   - `src/MTProtoTools/PasswordCalculator.php` (2FA SRP PBKDF2 + SHA512 + 2048-bit ModPow)
3. **Transport & Connection Sockets:**
   - `src/DataCenter.php` & `src/DataCenterConnection.php` (DC IP/Port management)
   - `src/Loop/Connection/` (`ReadLoop.php`, `WriteLoop.php`, Obfuscated & Abridged Transports)
4. **Session State DTO:**
   - Stateless `SessionData` class (holds `dc_id`, `auth_key`, `time_delta`, `seq_no`) - zero disk/db dependencies.

---

### ❌ What We Remove (The Madeline "Mini-OS" Bloat)
1. **Legacy In-House DB & ORM:**
   - `src/Db/MemoryArray.php`, `src/Db/CachedArray.php`, `src/Db/CachedStore.php`, `src/Db/Cache.php`
2. **Filesystem Serialization & IPC Locks:**
   - `src/Serialization.php`, `src/SessionPaths.php`, `flock()` `.safe.php` file locks, Unix domain socket IPC servers.
3. **Ad-Hoc Plugin System & Loops:**
   - `src/PluginEventHandler.php`, `src/EventHandlerIssue.php`, custom cron loops.

---

## 2. Step-by-Step Execution Plan

### Step 1: Create Extraction Branch
- Create branch `feat/clean-mtproto-extract`.

### Step 2: Build the Stateless `MtprotoClient` Core
- Create a pure, dependency-injected client:
  ```php
  namespace Danog\LaravelTelegram\MTProto;

  class MtprotoClient
  {
      public function __construct(int $apiId, string $apiHash) {}
      public function call(string $method, array $params = [], ?SessionData $session = null): array {}
  }
  ```
- Implement pure Diffie-Hellman key generation and 2FA SRP challenges directly returning clean strings.

### Step 3: Wire into `packages/laravel-telegram`
- Connect `TelegramClient` and `TelegramAuthService` to use `MtprotoClient`.
- Connect `TelegramIngestCommand` to listen to the socket and pipe updates into the Redis Stream.

### Step 4: Verification & Live Account Testing
- Verify against SQLite and PostgreSQL in-memory and local test databases.
- Test loading existing credentials and calling `users.getFullUser` or `messages.getDialogs`.

### Step 5: Package Isolation & Standalone Repo Preparation
- Ensure `packages/laravel-telegram/` is completely self-contained with its own `composer.json` (MIT license), ready to be pushed to a fresh `laravel-telegram` GitHub repository.
