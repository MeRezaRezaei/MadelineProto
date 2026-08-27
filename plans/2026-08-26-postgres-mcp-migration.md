# PostgreSQL / Redis Migration & Madeline-MCP Refactoring Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate legacy flat-file sessions to PostgreSQL, refactor `madeline-mcp` to use PostgreSQL/RelationalStore and Redis EventBus, and eliminate all file locks and `.safe.php` dependencies.

**Architecture:** PostgreSQL (`RelationalStore` + native MadelineProto DB backend) stores account records and MTProto state. `madeline-mcp` loads sessions via database settings and queries `RelationalStore`. `bin/madeline-migrate-session` converts disk sessions into database records.

**Tech Stack:** PHP 8.3+, PostgreSQL/SQLite PDO, Amp v3 / Revolt EventLoop, Redis Pub/Sub, MadelineProto v8.

**Spec:** `docs/superpowers/specs/2026-08-26-postgres-mcp-migration-design.md`

## Global Constraints
- No hardcoded passwords or file locks (`safe.php`, `ipcState.php`).
- Native Amp v3 / Revolt async compatibility.
- 100% test coverage for migration and MCP database integration.

---

### Task 1: Session Migrator Tool (`bin/madeline-migrate-session`)

**Files:**
- Create: `bin/madeline-migrate-session`
- Create: `tests/Migration/SessionMigratorTest.php`
- Modify: `src/Accounts/AccountManager.php`

**Interfaces:**
- Consumes: `sessions/<name>` directory with `safe.php` or `AccountManager` instances.
- Produces: `bin/madeline-migrate-session --session=main_account --dsn=<dsn>` CLI tool and test suite.

- [x] **Step 1: Write the failing test for session migration**
- [x] **Step 2: Run test to verify it fails**
- [x] **Step 3: Implement session migration logic and CLI script**
- [x] **Step 4: Run test to verify it passes**
- [x] **Step 5: Commit**

---

### Task 2: Refactor `madeline-mcp` to use Database Settings & RelationalStore

**Files:**
- Modify: `madeline-mcp/src/ApiClient.php`
- Modify: `madeline-mcp/bin/madeline-mcp`
- Test: `madeline-mcp/tests/AccountSessionTest.php`
- Test: `madeline-mcp/tests/McpServerTest.php`

**Interfaces:**
- Consumes: `MADELINE_DSN` / `DATABASE_URL` environment variables and `RelationalStore`.
- Produces: `ApiClient` constructing `Settings\Database\Postgres` or `Settings\Database\Memory` without creating disk session folders.

- [x] **Step 1: Write/update tests in `madeline-mcp/tests/` to assert database backend usage**
- [x] **Step 2: Run test to verify failure/expectations**
- [x] **Step 3: Update `ApiClient.php` to use Database settings and RelationalStore**
- [x] **Step 4: Run test suite to verify it passes**
- [x] **Step 5: Commit**

---

### Task 3: End-to-End Migration & MCP Verification

**Files:**
- Create: `tests/E2E/MigratedSessionE2ETest.php`
- Run: `bin/madeline-migrate-session` on `sessions/main_account`

**Interfaces:**
- Consumes: PostgreSQL database and migrated account `501558149`.
- Produces: Full E2E verification test confirming `get_me` and `list_conversations` work without disk session files.

- [x] **Step 1: Write `tests/E2E/MigratedSessionE2ETest.php`**
- [x] **Step 2: Run migration tool against `sessions/main_account`**
- [x] **Step 3: Execute full test suite including E2E and MCP tests**
- [x] **Step 4: Verify zero disk session lock files are created**
- [x] **Step 5: Commit**
