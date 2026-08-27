# PostgreSQL / Redis Migration & Madeline-MCP Refactoring Spec

## 1. Goal
Eliminate all file-based session locks (`safe.php`, `ipcState.php`, `.lock`) across the entire repository. Make PostgreSQL (`RelationalStore` + MadelineProto native database backend) and Redis (`EventBus`) the single source of truth for accounts, sessions, and events. Migrate the existing logged-in session (`main_account`) into PostgreSQL and verify MCP end-to-end without disk session files.

## 2. Architecture & Components

### 2.1 Database & Store Layer
- `danog\MadelineProto\Db\RelationalStore`: Houses user, chat, channel, dialog, message, and account entities.
- MadelineProto Database Settings: Native `danog\MadelineProto\Settings\Database\Postgres` or `Memory`/`SqlAbstract` connected via `MADELINE_DSN` / `DATABASE_URL`.

### 2.2 Session Migration Tool (`bin/madeline-migrate-session`)
- Inspects existing `sessions/main_account` or any target session directory.
- Extracts `api_id`, `api_hash`, `user_id`, `auth_state`, and session state.
- Upserts the account record into `RelationalStore` (`accounts` and `account_entities` tables) and loads it into the PostgreSQL backend.
- Verifies successful authorization from PostgreSQL without relying on local session files.

### 2.3 MCP Refactoring (`madeline-mcp/src/ApiClient.php`)
- Remove hardcoded `sessions/<name>` file directory lookups.
- Configure MadelineProto `Settings` using PostgreSQL database settings from environment (`MADELINE_DSN`, `DATABASE_URL`) or SQLite fallback.
- Integrate `RelationalStore` for accounts discovery (`listAccounts`, `getMe`, `getConversation`).

### 2.4 Event Dispatching & Daemon Synchronization
- `bin/madeline-daemon` boots with `RelationalStore`, `SyncLoop`, and `EventBus`.
- Events are broadcast over Redis `madeline:updates`.
- Hot-reload listeners over `madeline:control`.

## 3. Success & Verification Criteria
1. `bin/madeline-migrate-session` runs and migrates `sessions/main_account` into PostgreSQL/SQLite.
2. `madeline-mcp` tools (`get_me`, `list_conversations`, `get_login_state`) operate purely against the database session without reading/writing `.safe.php` or `.lock` files.
3. Unit, feature, and E2E integration test suites pass 100%.
