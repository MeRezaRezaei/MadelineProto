# Clean-Room MIT Laravel Telegram Package Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a 100% clean-room, MIT-licensed Telegram MTProto 2.0 & Bot client for Laravel under the `Rezarezaei\LaravelTelegram` namespace, completely independent of legacy AGPLv3 code, with support for dynamic multi-tenant credentials, bot accounts, and Mini App security.

**Architecture:** Pure protocol client (MTProto 2.0 crypto, SOCKS5 socket proxy, TL serialization) wrapped in a modern Laravel 10/11/12/13 ServiceProvider and Facade (`Telegram::user(...)`, `Telegram::bot(...)`), with zero database or filesystem locks.

**Tech Stack:** PHP 8.2+, OpenSSL (`aes-256-ecb`), `phpseclib3\Math\BigInteger`, Laravel Framework / Illuminate Support.

**Spec:** `specs/2026-08-27-clean-room-laravel-telegram-mit-spec.md`

## Global Constraints
- **License:** Strictly MIT License (Author: Reza Rezaei).
- **Namespace:** Strictly `Rezarezaei\LaravelTelegram\` (Package: `rezarezaei/laravel-telegram`). Zero occurrences of `danog` or `MadelineProto` in package namespace/code.
- **Stateless:** Zero forced database migrations or file locks inside the package; credentials and AuthKeys are passed dynamically at runtime with fallback to `.env`.
- **Dual Account Types:** Explicit support for both User MTProto accounts (`Telegram::user(...)`) and Bot accounts (`Telegram::bot(...)`).

---

### Task 1: Protocol Extraction & Clean-Room Architecture Documentation

**Files:**
- Create: `specs/protocol/mtproto-transport.md`
- Create: `specs/protocol/mtproto-srp-2fa.md`
- Create: `specs/protocol/tl-binary-format.md`

**Interfaces:**
- Consumes: Telegram MTProto 2.0 Public Documentation
- Produces: Formal technical documentation of framing, AES-256-IGE, SRP 2FA math, and TL binary format

- [ ] **Step 1: Write `mtproto-transport.md` documenting MTProto 2.0 framing, DC endpoints, and AES-256-IGE cipher**
- [ ] **Step 2: Write `mtproto-srp-2fa.md` documenting 2FA SRP algorithm ($A = g^a \pmod p$, $S = (B - k \cdot g^x)^{a + u \cdot x} \pmod p$, $M_1$)**
- [ ] **Step 3: Write `tl-binary-format.md` documenting Type Language byte-level packing (lengths, padding, ints, longs, strings)**
- [ ] **Step 4: Commit documentation**

```bash
git add specs/protocol/
git commit -m "docs(protocol): add clean-room MTProto 2.0, SRP 2FA, and TL specification references"
```

---

### Task 2: Rebrand Package Identity & Namespace to `Rezarezaei\LaravelTelegram`

**Files:**
- Modify: `packages/laravel-telegram/composer.json`
- Modify: `packages/laravel-telegram/config/telegram.php`
- Modify: `packages/laravel-telegram/src/TelegramServiceProvider.php`
- Modify: `packages/laravel-telegram/src/Facades/Telegram.php`
- Modify: `packages/laravel-telegram/README.md`
- Modify: `composer.json` (root dev mapping)

**Interfaces:**
- Consumes: Clean-room spec
- Produces: Package `rezarezaei/laravel-telegram` with namespace `Rezarezaei\LaravelTelegram\`

- [ ] **Step 1: Update `packages/laravel-telegram/composer.json` with MIT license, Reza Rezaei author, and `Rezarezaei\\LaravelTelegram\\` PSR-4**
- [ ] **Step 2: Update all file namespaces in `packages/laravel-telegram/src/`**
- [ ] **Step 3: Update root `composer.json` autoload mappings and run `composer dump-autoload`**
- [ ] **Step 4: Verify autoloading**

Run: `composer dump-autoload`
Expected: Autoload files generated successfully

- [ ] **Step 5: Commit**

```bash
git add packages/laravel-telegram/ composer.json
git commit -m "refactor(namespace): rebrand package to rezarezaei/laravel-telegram under MIT license"
```

---

### Task 3: Implement Clean-Room Cryptography & SOCKS5 Networking

**Files:**
- Create: `packages/laravel-telegram/src/MTProto/Crypto/AesIge.php`
- Create: `packages/laravel-telegram/src/MTProto/Crypto/PasswordCalculator.php`
- Create: `packages/laravel-telegram/src/MTProto/TL/TLSerializer.php`
- Create: `packages/laravel-telegram/src/MTProto/Transport/Socks5Proxy.php`
- Test: `packages/laravel-telegram/tests/MtprotoCryptoAndTlTest.php`

**Interfaces:**
- Consumes: OpenSSL, phpseclib3 BigInteger
- Produces: `AesIge::encrypt/decrypt`, `PasswordCalculator::computeSrpProof`, `TLSerializer::packString/unpackString`, `Socks5Proxy::connect`

- [ ] **Step 1: Write test for AesIge, SRP PasswordCalculator, and TLSerializer in `Rezarezaei\LaravelTelegram\Tests`**
- [ ] **Step 2: Run test to verify it fails/passes**

Run: `./vendor/bin/phpunit packages/laravel-telegram/tests/MtprotoCryptoAndTlTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add packages/laravel-telegram/src/MTProto/ packages/laravel-telegram/tests/MtprotoCryptoAndTlTest.php
git commit -m "feat(mtproto): implement clean-room AES-IGE, SRP 2FA, and SOCKS5 proxy under Rezarezaei namespace"
```

---

### Task 4: Implement Dynamic Multi-Tenant MTProto Client & BotClient

**Files:**
- Create: `packages/laravel-telegram/src/MTProto/SessionData.php`
- Create: `packages/laravel-telegram/src/MTProto/Client.php`
- Create: `packages/laravel-telegram/src/Services/BotClient.php`
- Create: `packages/laravel-telegram/src/Services/UserAccountScope.php`
- Create: `packages/laravel-telegram/src/Services/TelegramClient.php`
- Create: `packages/laravel-telegram/src/Facades/Telegram.php`
- Test: `packages/laravel-telegram/tests/TelegramClientTest.php`

**Interfaces:**
- Consumes: `SessionData`, `MTProto\Client`, `BotClient`
- Produces: `Telegram::user(...)`, `Telegram::bot(...)`, `Telegram::forAccount(...)`

- [ ] **Step 1: Write failing/passing test for multi-tenant runtime credentials vs single-tenant .env fallback and BotClient**
- [ ] **Step 2: Implement `TelegramClient`, `UserAccountScope`, `BotClient`, and `Telegram` Facade**
- [ ] **Step 3: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/laravel-telegram/tests/TelegramClientTest.php`
Expected: PASS (100%)

- [ ] **Step 4: Commit**

```bash
git add packages/laravel-telegram/src/ packages/laravel-telegram/tests/TelegramClientTest.php
git commit -m "feat(client): implement dynamic multi-tenant MTProto User client and Bot client"
```

---

### Task 5: Implement Telegram Mini App Security Middleware

**Files:**
- Create: `packages/laravel-telegram/src/Http/Middleware/VerifyTelegramMiniAppInitData.php`
- Test: `packages/laravel-telegram/tests/MiniAppValidatorTest.php`

**Interfaces:**
- Consumes: Telegram Mini App `initData` string & Bot Token
- Produces: `VerifyTelegramMiniAppInitData::validateInitData(string $initData, string $botToken): ?array`

- [ ] **Step 1: Write test for Mini App HMAC-SHA256 signature verification and tamper detection**
- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/laravel-telegram/tests/MiniAppValidatorTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add packages/laravel-telegram/src/Http/ packages/laravel-telegram/tests/MiniAppValidatorTest.php
git commit -m "feat(miniapp): add cryptographic HMAC-SHA256 Mini App initData validation middleware"
```

---

### Task 6: Full Suite Verification & Standalone Repo Export Readiness

**Files:**
- Create: `packages/laravel-telegram/README.md`
- Create: `packages/laravel-telegram/LICENSE` (MIT)

- [ ] **Step 1: Run all test suites across the package**

Run: `./vendor/bin/phpunit packages/laravel-telegram/tests/`
Expected: OK (all tests pass)

- [ ] **Step 2: Ensure zero Danog references exist in `packages/laravel-telegram/`**

Run: `grep -ri "danog" packages/laravel-telegram/`
Expected: No matches

- [ ] **Step 3: Push changes to GitHub fork**

```bash
git push fork feat/clean-mtproto-extract
```
