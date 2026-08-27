# madeline-mcp: Sanitizer Fix + Compatible-Layer Extensions

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the test suite fully green and extend the Compatible (user-like) tool surface with `get_profile` and `get_media`, then capture all verified work in commits.

**Architecture:** The MCP server exposes a tiered tool surface. `ToolCatalog::all($mode)` returns tools tagged `meta`/`compatible`/`advanced`; `McpServer` filters by mode and intercepts `set_tool_mode`. `ResponseSanitizer` compacts raw Telegram TL into bounded JSON. The two-tier design (Compatible default / Advanced raw) is already implemented and verified; this plan (1) fixes one pre-existing sanitizer bug, (2) commits the already-written two-tier work, (3) adds two new Compatible tools (`get_profile`, `get_media`). `search_messages` already exists as a Compatible tool and is verified as present — no new work beyond locking it into the catalog test.

**Tech Stack:** PHP 8.1+, MadelineProto 8.7.0, PHPUnit 9.6. Test command: `vendor/bin/phpunit -c madeline-mcp/phpunit.xml --no-coverage` (run from repo root `/home/me/Documents/projects/MadelineProto`).

**Spec:** Nowledge Mem design memory `5b77566e-4ae9-4c32-916e-64ea2f47dcbb` (two-tier design + snapshot TTL + static-ID facts) and the preceding session. The lone failing test (`SanitizerTest::testCallMethodProjectsContainers`) is pre-existing and was reproduced on the unmodified baseline via `git stash`.

## Global Constraints

- PHP 8.1+ strict_types everywhere; `declare(strict_types=1)` at top of every PHP file.
- MadelineProto 8.7.0; do NOT call Telegram in unit tests (no live session). Mirror existing test style: catalog-contract tests only (advertised / tier / schema), not live-API behavior.
- Tool names: `snake_case`. New Compatible tools must be added to BOTH the `$raw` array in `ToolCatalog::all()` AND the `$curatedNames` list, so they get the `compatible` tier.
- `peer` argument is a **string** (id, username, @username, or phone).
- Every `call()` handler must go through `twrap()` (normalizes exceptions, records FLOOD_WAITs).
- Keep payloads bounded: use `ResponseSanitizer::clean()` on any raw TL before returning so base64 blobs become `[blob N B]` markers.
- Do NOT push to remote; commits are local only. Stage only the intended files (there is an unrelated untracked `.superpowers/` dir — leave it untracked).

---

### Task 1: Fix pre-existing SanitizerTest failure

**Files:**
- Modify: `madeline-mcp/src/ResponseSanitizer.php:184-191` (end of `projectContainers`)
- Test: `madeline-mcp/tests/SanitizerTest.php` (already asserts the expected shape; no change needed)

**Interfaces:**
- Consumes: `ResponseSanitizer::project('call_method', $raw)` — already wired.
- Produces: a `call_method` projection that retains the compacted `users` and `chats` reference tables (so downstream assertions and the AI both see them).

- [x] **Step 1: Confirm current failure (baseline)**

Run: `cd /home/me/Documents/projects/MadelineProto && vendor/bin/phpunit -c madeline-mcp/phpunit.xml madeline-mcp/tests/SanitizerTest.php --no-coverage 2>/dev/null | tail -5`
Expected: `ERRORS! Tests: 7, Errors: 1` with `Undefined array key "users"` at `SanitizerTest.php:133`.

- [x] **Step 2: Replace the over-aggressive drop block**

In `madeline-mcp/src/ResponseSanitizer.php`, replace the comment + `if/elseif` near the end of `projectContainers`:

OLD:
```php
        // Names are now embedded in the projected messages/dialogs. For list
        // scans the raw reference tables are pure bulk (and a dialogs fetch
        // already carries previews), so drop them to stay under the proxy cap.
        if (isset($result['dialogs'])) {
            unset($result['messages'], $result['users'], $result['chats']);
        } elseif (isset($result['messages'])) {
            unset($result['users'], $result['chats']);
        }
```

NEW:
```php
        // Names are embedded in the projected messages/dialogs, but the
        // compacted users/chats reference tables are tiny after projection and
        // useful to keep (ids resolve to names without re-fetching). Only drop
        // the bulk messages list when dialogs already carry their previews.
        if (isset($result['dialogs'])) {
            unset($result['messages']);
        }
```

- [x] **Step 3: Run SanitizerTest to verify it passes**

Run: `cd /home/me/Documents/projects/MadelineProto && vendor/bin/phpunit -c madeline-mcp/phpunit.xml madeline-mcp/tests/SanitizerTest.php --no-coverage 2>/dev/null | tail -5`
Expected: `OK (7 tests, 39 assertions)`.

- [x] **Step 4: Commit**

```bash
cd /home/me/Documents/projects/MadelineProto
git add madeline-mcp/src/ResponseSanitizer.php
git commit -m "fix: retain compacted users/chats in call_method container projection

projectContainers dropped the users/chats tables whenever a messages or
dialogs result was present, breaking SanitizerTest. The tables are already
compacted to minimal fields (id/name/username/is_bot, id/title/type) so
they stay bounded; keep them so ids resolve to names without a re-fetch."
```

---

### Task 2: Commit the already-implemented two-tier work

**Files (already on disk, uncommitted — verify each is the two-tier version):**
- `madeline-mcp/src/ToolCatalog.php` (tiering: `all($mode)`, `set_tool_mode`, fact-rich descriptions)
- `madeline-mcp/src/McpServer.php` (mode property + `tools/list` mode + `set_tool_mode` interception)
- `madeline-mcp/src/SnapshotStore.php` (TTL_SECONDS + `isLive()` expiry)
- `madeline-mcp/tests/ToolCatalogTest.php` (tier tests)
- `madeline-mcp/tests/McpServerTest.php` (mode tests)
- `madeline-mcp/tests/SnapshotStoreTest.php` (TTL test)

**Interfaces:**
- No code change in this task; pure capture of verified work.

- [x] **Step 1: Verify the full suite is green (this plan's other changes not yet applied)**

Run: `cd /home/me/Documents/projects/MadelineProto && vendor/bin/phpunit -c madeline-mcp/phpunit.xml --no-coverage 2>/dev/null | tail -4`
Expected: full run shows only the 1 pre-existing SanitizerTest error (Task 1 will have fixed it by now if applied first; if Task 1 already merged, expect 0 errors). Confirm `SnapshotStoreTest`, `ToolCatalogTest`, `McpServerTest` all pass.
Note: run Task 1 before this task so the committed state is fully green.

- [x] **Step 2: Stage only the six two-tier files (leave `.superpowers/` untracked)**

```bash
cd /home/me/Documents/projects/MadelineProto
git add madeline-mcp/src/ToolCatalog.php madeline-mcp/src/McpServer.php madeline-mcp/src/SnapshotStore.php \
        madeline-mcp/tests/ToolCatalogTest.php madeline-mcp/tests/McpServerTest.php madeline-mcp/tests/SnapshotStoreTest.php
git status --short
```
Expected: only those 6 files staged; `.superpowers/` NOT staged.

- [x] **Step 3: Commit**

```bash
git commit -m "feat: two-tier tool surface (Compatible/Advanced) + snapshot TTL

Compatible (default) shows only user-like Telegram tools; Advanced exposes
the raw MadelineProto/Telegram method layer (full objects); 'all' shows both.
set_tool_mode switches the advertised surface. SnapshotStore tokens now expire
after TTL_SECONDS (300) so stale frozen sorts are rejected. Tool descriptions
encode the design facts: Telegram IDs are globally static; sort_token is a
short-lived cache."
```

---

### Task 3: Add Compatible `get_profile` and `get_media` tools

**Files:**
- Modify: `madeline-mcp/src/ToolCatalog.php` (import `ResponseSanitizer`; add 2 tool defs; add 2 names to `$curatedNames`; 2 handlers; 2 `call()` cases)
- Modify: `madeline-mcp/tests/ToolCatalogTest.php` (assert new tools advertised/tiered)
- Modify: `madeline-mcp/tests/McpServerTest.php` (update `testToolsList` count 20 → 22)

**Interfaces:**
- Consumes: `ToolCatalog::api()`, `ToolCatalog::twrap()`, `ToolCatalog::client` (via `$this->client->call(...)`), `MadelineMcp\ResponseSanitizer::clean()`.
- Produces: `get_profile` (Compatible) returns cleaned full-user profile; `get_media` (Compatible) returns media metadata (no base64 blobs) for a message.

- [x] **Step 1: Write the failing catalog tests**

In `madeline-mcp/tests/ToolCatalogTest.php`, add:
```php
    public function testProfileAndMediaAreCompatibleTools(): void
    {
        $all = \array_column($this->catalog()->all('all'), 'name');
        self::assertContains('get_profile', $all);
        self::assertContains('get_media', $all);

        $compatible = \array_column($this->catalog()->all(), 'name');
        self::assertContains('get_profile', $compatible, 'get_profile must be Compatible-tier');
        self::assertContains('get_media', $compatible, 'get_media must be Compatible-tier');
        self::assertNotContains('get_profile', \array_column($this->catalog()->all('advanced'), 'name'), 'hidden in advanced mode');
        self::assertNotContains('get_media', \array_column($this->catalog()->all('advanced'), 'name'), 'hidden in advanced mode');
    }
```

- [x] **Step 2: Run the new test to verify it fails**

Run: `cd /home/me/Documents/projects/MadelineProto && vendor/bin/phpunit -c madeline-mcp/phpunit.xml madeline-mcp/tests/ToolCatalogTest.php --no-coverage 2>/dev/null | tail -4`
Expected: the new test FAILS (get_profile / get_media not yet defined).

- [x] **Step 3: Add the import**

At the top of `madeline-mcp/src/ToolCatalog.php`, after `use MadelineMcp\SnapshotStore;` add:
```php
use MadelineMcp\ResponseSanitizer;
```

- [x] **Step 4: Add the two tool definitions to `all()` `$raw` array**

Insert after the `get_conversation` tool def (before the `call_method` def, near line 343):
```php
            [
                'name' => 'get_profile',
                'description' => '[Compatible] Get a user or bot profile (bio, username, photo, bot/online info) resolved from a peer (id, username, @username, or phone). Compacted and bounded; use Advanced call_method for the raw full_user object. Telegram user IDs are GLOBALLY STATIC across all accounts — safe to rely on.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'peer' => self::str('User/bot peer: id, username, @username, or phone.'),
                ], ['peer']),
            ],
            [
                'name' => 'get_media',
                'description' => '[Compatible] Return the MEDIA METADATA of a message (type, mime, size, dimensions) without downloading it — so the AI can see what a message carries without huge file payloads. Use download_media to save the file to disk. Pass peer + message_id.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'peer' => self::str('Chat peer: id, username, or @username.'),
                    'message_id' => self::int('The ID of the message whose media to inspect.'),
                ], ['peer', 'message_id']),
            ],
```

- [x] **Step 5: Register the two names as Compatible tier**

In the `$curatedNames` array (currently lines 359-365), add `'get_profile'` and `'get_media'`:
```php
        $curatedNames = [
            'list_accounts', 'add_account', 'start_login', 'submit_login_code',
            'submit_password', 'get_login_state', 'get_me', 'list_dialogs',
            'send_message', 'send_media', 'download_media', 'delete_messages',
            'read_history', 'resolve_peer', 'search_messages', 'get_full_chat_info',
            'list_folders', 'list_conversations', 'get_conversation',
            'get_profile', 'get_media',
        ];
```

- [x] **Step 6: Add the two `call()` dispatch cases**

In the `match ($name)` block in `call()` (currently lines 427-450), add:
```php
            'get_profile' => $this->getProfile($args),
            'get_media' => $this->getMedia($args),
```

- [x] **Step 7: Implement the two handlers**

Add near `getFullChatInfo()` (after line 634):
```php
    /**
     * Compatible profile lookup for a person (user/bot). Returns the compacted
     * full_user profile; the AI can drop to Advanced call_method(users.getFullUser)
     * for the raw object.
     */
    private function getProfile(array $args): mixed
    {
        return $this->twrap(function () use ($args) {
            $api = $this->api($args['session_name'] ?? null);
            $info = $api->getInfo($args['peer']);
            $id = $info['id']
                ?? ($info['User']['id'] ?? null)
                ?? ($info['Chat']['id'] ?? null);
            if ($id === null) {
                return ['_error' => true, 'message' => 'cannot resolve peer to an id'];
            }
            $full = $this->client->call('users.getFullUser', ['id' => $id], $args['session_name'] ?? null);

            return ResponseSanitizer::clean($full);
        });
    }

    /**
     * Compatible media inspection: returns the message's media metadata (type,
     * mime, size, dimensions) WITHOUT downloading, so the AI sees what a message
     * carries without a multi-MB payload. Blobs are replaced by size markers.
     */
    private function getMedia(array $args): mixed
    {
        return $this->twrap(function () use ($args) {
            $api = $this->api($args['session_name'] ?? null);
            $parsedPeer = $api->getInfo($args['peer']);
            $res = ($parsedPeer['type'] === 'channel' || $parsedPeer['type'] === 'supergroup')
                ? $api->channels->getMessages(['channel' => $args['peer'], 'id' => [$args['message_id']]])
                : $api->messages->getMessages(['id' => [$args['message_id']]]);
            $msg = $res['messages'][0] ?? null;
            if (!$msg || (isset($msg['_']) && $msg['_'] === 'messageEmpty')) {
                return ['_error' => true, 'message' => 'Message not found or empty.'];
            }
            if (!isset($msg['media'])) {
                return ['has_media' => false, 'id' => $msg['id']];
            }

            return [
                'has_media' => true,
                'id' => $msg['id'],
                'media' => ResponseSanitizer::clean($msg['media']),
            ];
        });
    }
```

- [x] **Step 8: Update the `tools/list` count assertion**

In `madeline-mcp/tests/McpServerTest.php`, change `testToolsList` line 47:
```php
        self::assertSame(20, \count($resp['result']['tools']));
```
to:
```php
        self::assertSame(22, \count($resp['result']['tools']));
```

- [x] **Step 9: Run the affected suites**

Run: `cd /home/me/Documents/projects/MadelineProto && vendor/bin/phpunit -c madeline-mcp/phpunit.xml madeline-mcp/tests/ToolCatalogTest.php madeline-mcp/tests/McpServerTest.php --no-coverage 2>/dev/null | tail -4`
Expected: `OK` for both (ToolCatalogTest 8 tests, McpServerTest 9 tests with count now 22).

- [x] **Step 10: Commit**

```bash
cd /home/me/Documents/projects/MadelineProto
git add madeline-mcp/src/ToolCatalog.php madeline-mcp/tests/ToolCatalogTest.php madeline-mcp/tests/McpServerTest.php
git commit -m "feat: Compatible get_profile and get_media tools

get_profile returns a compacted full_user profile for a peer; get_media
returns a message's media metadata without downloading (blobs replaced by
size markers). Both are Compatible-tier (hidden in advanced mode) and go
through twrap + ResponseSanitizer::clean for bounded, safe output."
```

---

### Task 4: Final end-to-end verification

**Files:** none changed; verification only.

**Interfaces:** n/a.

- [x] **Step 1: Lint all touched PHP files**

Run:
```bash
cd /home/me/Documents/projects/MadelineProto
for f in madeline-mcp/src/ResponseSanitizer.php madeline-mcp/src/ToolCatalog.php madeline-mcp/src/McpServer.php madeline-mcp/src/SnapshotStore.php madeline-mcp/tests/SanitizerTest.php madeline-mcp/tests/ToolCatalogTest.php madeline-mcp/tests/McpServerTest.php madeline-mcp/tests/SnapshotStoreTest.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` for all 8 files.

- [x] **Step 2: Run the FULL test suite and capture the exit code**

Run: `cd /home/me/Documents/projects/MadelineProto && vendor/bin/phpunit -c madeline-mcp/phpunit.xml --no-coverage 2>/dev/null; echo "PHPUNIT_EXIT_CODE=$?"`
Expected: `Tests: 67, Assertions: <n>, Errors: 0, Skipped: 1.` and `PHPUNIT_EXIT_CODE=0` (the single skipped test is pre-existing and unrelated).

- [x] **Step 3: Report verified status with evidence**

State the final counts (tests run, failures, exit code) from Step 2. Do not claim success without this output.

---

## Self-Review Notes

- **Spec coverage:** (1) sanitizer fix → Task 1; (2) commit two-tier → Task 2; (3) extend Compatible layer → Task 3 (`get_profile`, `get_media`; `search_messages` already verified present in `curatedNames` and at `ToolCatalog.php:293-302` / `:621-629`). All three user-requested items covered.
- **No placeholders:** every step has concrete code or exact command.
- **Type consistency:** handler names `getProfile`/`getMedia` match the `call()` cases and `$curatedNames` entries; import `ResponseSanitizer` added once. `peer` is a string throughout.
- **Count dependency:** `testToolsList` updated 20 → 22 after adding 2 Compatible tools; if more Compatible tools are later added, this assertion must move again.
