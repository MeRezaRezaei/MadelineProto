# Bot Bridge v2 (Event-Sourcing) + Madeline Assistant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the Telegram-bot tool bridge on event-sourcing semantics (snapshot → act → diff), add a `bot.read` observation primitive and button-freshness guard, then finish the Madeline Assistant (OmniRoute agent bot) on top of it.

**Architecture:** Telegram bots are async UI components over a shared transcript, not RPC endpoints. `bot.invoke` therefore returns an ordered diff of everything the bot did (new messages + edits collected until a quiet window), not a single "reply". Multi-step flows (BotFather wizard) are driven by *reading visible state* (`bot.read`) and deciding the next input — no step-order assumptions. The assistant is a long-lived bot-session process whose owner-chat updates are detected with the same diff primitive; its agent loop calls OmniRoute with the full madeline-mcp toolset via ToolBridge.

**Tech Stack:** PHP 8.2+, MadelineProto v8 (danog), PHPUnit, curl against OpenAI-compatible OmniRoute gateway.

## Global Constraints

- Repo root: `/home/merezarezaei/Documents/projects/MadelineProto`; subproject `madeline-mcp/`. PSR-4: `MadelineMcp\` → `madeline-mcp/src/`.
- **File editing MUST use bash** (`cat > file <<'EOF'`, python patch). Read/Edit/Write tools cannot access `/home/**`.
- Run tests from repo root: `vendor/bin/phpunit -c madeline-mcp/phpunit.xml --filter <Name>`; full suite before every commit.
- Every PHP file: `declare(strict_types=1);`, `\`-prefixed global functions/classes (existing style).
- **MadelineProto's global error handler converts warnings (even `@`-suppressed) to exceptions.** Always guard filesystem ops with explicit `is_file()`/`is_dir()` checks.
- **No secrets in git**: OmniRoute key lives in `~/.config/madeline-mcp/omniroute.json` (already there); bot tokens only in `cache/assistant-state.json` (cache/ is gitignored).
- After changing `madeline-mcp/src/**`: `sudo systemctl restart mcpproxy` (daemon holds stale stdio children). New tools are auto-quarantined → approve with `mcpproxy tools approve "madeline-mcp:<tool>"`.
- Git: branch `v8`, push remote `fork` (never push origin=danog). Conventional commits.
- Known live state: BotFather chat is mid-wizard awaiting a valid username (last incoming msg "Sorry, this username is invalid."). Ensure v2 must resume cleanly from this.
- Assistant bot constants: name `Madeline Assistant`, username `madeline_501558149_bot` (override via env `MADELINE_ASSISTANT_USERNAME`). Owner id 501558149 must NEVER be hardcoded outside bin defaults already present in `AssistantBot::DEFAULT_USERNAME` pattern... correction: read owner dynamically via `$api->api()->getSelf()`; username default may embed nothing account-specific — use env or literal `madeline_assistant_bot` fallback? **Decision: keep existing constant `madeline_501558149_bot` (it is already public in this private repo context) but prefer env override; ensure() reads owner id live, never hardcodes it.**

---

### Task 1: Pure event-diff primitives in BotInvoker

**Files:**
- Modify: `madeline-mcp/src/Bots/BotInvoker.php`
- Modify: `madeline-mcp/src/Bots/BotScanner.php` (add `buttonsOfMessage`)
- Test: `madeline-mcp/tests/BridgeDiffTest.php`

**Interfaces:**
- Produces:
  - `BotInvoker::incomingOnly(array $messages): array` — filters to real incoming text/media messages (drops `out===true`, service actions, non-arrays).
  - `BotInvoker::fingerprint(array $m): string` — `md5(edit_date . '|' . message)`.
  - `BotInvoker::snapshot(array $messages, int $depth = 5): array{max_in_id:int, prints:array<int,string>}`.
  - `BotInvoker::diffEvents(array $beforeMsgs, array $afterMsgs): list<array{id:int,type:'new'|'edit',text:string,buttons:array}>` — ordered by id asc.
  - `BotScanner::buttonsOfMessage(array $m): array<string,array{type:string,data?:string,url?:string,msg_id:int}>` — inline buttons of ONE message (same meta shape as scan maps).

- [ ] **Step 1: Write failing tests**

```php
<?php
declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\Bots\BotInvoker;
use MadelineMcp\Bots\BotScanner;
use PHPUnit\Framework\TestCase;

final class BridgeDiffTest extends TestCase
{
    private function msg(int $id, bool $out, string $text = '', string $type = 'message', int $editDate = 0): array
    {
        return ['_' => $type, 'id' => $id, 'out' => $out, 'message' => $text, 'edit_date' => $editDate];
    }

    public function testIncomingOnlyDropsOutgoingAndService(): void
    {
        $msgs = [$this->msg(3, true, 'me'), $this->msg(4, false, 'bot'), ['_' => 'messageService', 'id' => 5, 'out' => false]];
        $in = BotInvoker::incomingOnly($msgs);
        $this->assertSame([4], array_map(static fn($m) => $m['id'], $in));
    }

    public function testDiffNewAndEdit(): void
    {
        $before = [$this->msg(10, false, 'page 1')];
        $after = [$this->msg(10, false, 'page 2', 'message', 1700000100), $this->msg(11, false, 'brand new')];
        $ev = BotInvoker::diffEvents($before, $after);
        $this->assertCount(2, $ev);
        $this->assertSame(['id' => 10, 'type' => 'edit', 'text' => 'page 2'], array_intersect_key($ev[0], ['id' => 1, 'type' => 1, 'text' => 1]));
        $this->assertSame('new', $ev[1]['type']);
        $this->assertSame(11, $ev[1]['id']);
    }

    public function testDiffIgnoresUnchanged(): void
    {
        $before = [$this->msg(7, false, 'same')];
        $this->assertSame([], BotInvoker::diffEvents($before, $before));
    }

    public function testSnapshotTracksMaxIncomingAndPrints(): void
    {
        $msgs = [$this->msg(20, false, 'a'), $this->msg(21, true, 'me'), $this->msg(22, false, 'b')];
        $s = BotInvoker::snapshot($msgs);
        $this->assertSame(22, $s['max_in_id']);
        $this->assertArrayHasKey(22, $s['prints']);
        $this->assertArrayNotHasKey(21, $s['prints']);
    }

    public function testButtonsOfMessageParsesCallbackButtons(): void
    {
        $m = ['id' => 30, 'out' => false, 'reply_markup' => ['rows' => [['buttons' => [
            ['_' => 'keyboardButtonCallback', 'text' => 'Next', 'data' => 'pg2'],
            ['_' => 'keyboardButtonUrl', 'text' => 'Docs', 'url' => 'https://x'],
        ]]]]];
        $b = BotScanner::buttonsOfMessage($m);
        $this->assertSame('callback', $b['Next']['type']);
        $this->assertSame(base64_encode('pg2'), $b['Next']['data']);
        $this->assertSame(30, $b['Next']['msg_id']);
        $this->assertSame('url', $b['Docs']['type']);
    }
}
```

- [ ] **Step 2: Run tests, verify failure**

Run: `cd /home/merezarezaei/Documents/projects/MadelineProto && vendor/bin/phpunit -c madeline-mcp/phpunit.xml --filter BridgeDiff`
Expected: FAIL — methods `incomingOnly|fingerprint|snapshot|diffEvents|buttonsOfMessage` not found.

- [ ] **Step 3: Implement primitives**

In `BotScanner.php` ADD (reuse the switch style already inside `fromHistory`):

```php
/** Inline buttons of ONE message, same meta shape as scan maps. */
public static function buttonsOfMessage(array $m): array
{
    $out = [];
    foreach ((array) (($m['reply_markup'] ?? null)['rows'] ?? []) as $row) {
        foreach ((array) ($row['buttons'] ?? []) as $b) {
            if (!\is_array($b)) {
                continue;
            }
            $t = \is_string($b['text'] ?? null) ? $b['text'] : '';
            if ($t === '') {
                continue;
            }
            switch ($b['_'] ?? '') {
                case 'keyboardButtonUrl':
                    $out[$t] = ['type' => 'url', 'url' => (string) ($b['url'] ?? ''), 'msg_id' => (int) ($m['id'] ?? 0)];
                    break;
                case 'keyboardButtonCallback':
                    $out[$t] = ['type' => 'callback', 'data' => \base64_encode((string) ($b['data'] ?? '')), 'msg_id' => (int) ($m['id'] ?? 0)];
                    break;
            }
        }
    }
    return $out;
}
```

In `BotInvoker.php` ADD:

```php
public const QUIET_SECONDS = 2.0;
public const POLL_INTERVAL = 1;

/** @return list<array> incoming non-service messages */
public static function incomingOnly(array $messages): array
{
    $out = [];
    foreach ($messages as $m) {
        if (\is_array($m) && ($m['_'] ?? 'message') === 'message' && ($m['out'] ?? false) === false) {
            $out[] = $m;
        }
    }
    return $out;
}

public static function fingerprint(array $m): string
{
    return \md5(((int) ($m['edit_date'] ?? 0)) . '|' . ((string) ($m['message'] ?? '')));
}

/** @return array{max_in_id:int, prints:array<int,string>} */
public static function snapshot(array $messages, int $depth = 5): array
{
    $in = self::incomingOnly($messages);
    $maxIn = 0;
    $prints = [];
    foreach ($in as $m) {
        $id = (int) ($m['id'] ?? 0);
        $maxIn = \max($maxIn, $id);
        $prints[$id] = self::fingerprint($m);
    }
    // Keep fingerprints for at most $depth most recent messages.
    return ['max_in_id' => $maxIn, 'prints' => \array_slice($prints, -$depth, null, true)];
}

/**
 * Ordered NEW/EDIT events between two transcript captures.
 *
 * @return list<array{id:int,type:string,text:string,buttons:array}>
 */
public static function diffEvents(array $beforeMsgs, array $afterMsgs): array
{
    $bIdx = [];
    foreach (self::incomingOnly($beforeMsgs) as $m) {
        $bIdx[(int) ($m['id'] ?? 0)] = $m;
    }
    $events = [];
    foreach (self::incomingOnly($afterMsgs) as $m) {
        $id = (int) ($m['id'] ?? 0);
        $fp = self::fingerprint($m);
        $was = isset($bIdx[$id]) ? self::fingerprint($bIdx[$id]) : null;
        if ($was === $fp) {
            continue;
        }
        $events[] = [
            'id' => $id,
            'type' => $was === null ? 'new' : 'edit',
            'text' => \is_string($m['message'] ?? null) ? $m['message'] : '',
            'buttons' => BotScanner::buttonsOfMessage($m),
        ];
    }
    \usort($events, static fn($a, $b) => $a['id'] <=> $b['id']);
    return $events;
}
```

- [ ] **Step 4: Run tests, verify pass**

Run: `vendor/bin/phpunit -c madeline-mcp/phpunit.xml --filter BridgeDiff`
Expected: PASS (6 tests).

- [ ] **Step 5: Full suite + commit**

Run: `vendor/bin/phpunit -c madeline-mcp/phpunit.xml`
Expected: all green.

```bash
git add madeline-mcp/src/Bots madeline-mcp/tests/BridgeDiffTest.php
git commit -m "feat(bots): pure snapshot/fingerprint/diff primitives for transcript event sourcing"
```

---

### Task 2: invoke() v2 — collect all events until quiet window

**Files:**
- Modify: `madeline-mcp/src/Bots/BotInvoker.php` (rewrite `invoke()` body; new `captureTail()`)
- Test: extend `madeline-mcp/tests/BotScannerTest.php`

**Interfaces:**
- Consumes: Task 1 primitives; existing `BotScanner::classifyAction/callbackDataFor/msgIdFor`.
- Produces (NEW result shape consumed by Tasks 4–6):
  - `invoke(API $api, string $peer, string $action, array $map, int $waitSeconds = 15, float $quietSeconds = 2.0): array`
    keys: `action, kind, sent, events:list<event>, response:string(joined texts), buttons:array(merged, later wins), reply_msg_id:?int, callback_answer:?array` AND on hard failure `_error:true,message` (unchanged fail contract).
  - Private network helper `captureTail(API $api, string $peer, int $limit = 10): array` (returns `[]` on any Throwable).

- [ ] **Step 1: Write failing test for the pure collection loop**

Add to `BotScannerTest.php` (collection loop extracted as testable static):

```php
public function testCollectLoopStopsOnQuietWindowAndMerges(): void
{
    // Simulated clock: each poll advances 1s; deltas appear at t=1 only.
    $polls = [
        [['id' => 5, 'out' => false, 'message' => 'hello']],
        [['id' => 5, 'out' => false, 'message' => 'hello']],
        [['id' => 5, 'out' => false, 'message' => 'hello']],
    ];
    $res = BotInvoker::collectUntilQuiet(
        static function () use (&$polls) { return \array_shift($polls) ?? []; },
        [],           // baseline msgs
        15.0,         // max wait
        2.0,          // quiet seconds
        1             // poll interval
    );
    $this->assertCount(1, $res['events']);
    $this->assertSame('hello', $res['response']);
}
```

- [ ] **Step 2: Verify failure**

Run: `vendor/bin/phpunit -c madeline-mcp/phpunit.xml --filter collectUntilQuiet`
Expected: FAIL — method missing.

- [ ] **Step 3: Implement collectUntilQuiet + rewrite invoke()**

```php
/**
 * Poll $fetch() until no NEW diffs for $quietSeconds or $maxWait elapsed.
 * $fetch returns transcript messages (raw TL arrays). Returns {events,response,buttons}.
 */
public static function collectUntilQuiet(callable $fetch, array $baselineMsgs, float $maxWait, float $quietSeconds = 2.0, int $pollInterval = 1): array
{
    $seen = [];
    $events = [];
    $buttons = [];
    $deadline = \microtime(true) + $maxWait;
    $lastDelta = \microtime(true);
    while (true) {
        \sleep($pollInterval);
        $now = \microtime(true);
        $ev = self::diffEvents($baselineMsgs, (array) $fetch());
        $fresh = [];
        foreach ($ev as $e) {
            $k = $e['id'] . ':' . \md5($e['text']);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $fresh[] = $e;
            }
        }
        if ($fresh !== []) {
            foreach ($fresh as $e) {
                $events[] = $e;
                foreach ($e['buttons'] as $txt => $meta) {
                    $buttons[$txt] = $meta;
                }
            }
            $baselineMsgs = (array) $fetch(); // hmm see Step note below
            $lastDelta = $now;
        } elseif ($now - $lastDelta >= $quietSeconds || $now >= $deadline) {
            break;
        }
    }
    $texts = \array_filter(\array_map(static fn($e) => $e['text'], $events), static fn($t) => $t !== '');
    return ['events' => $events, 'response' => \trim(\implode("\n---\n", $texts)), 'buttons' => $buttons];
}
```

NOTE for implementer: do NOT call `$fetch()` twice per iteration (double fetch above is wrong). Correct inner loop: fetch ONCE into `$nowMsgs`, diff vs `$baselineMsgs`, on fresh events set `$baselineMsgs=$nowMsgs`. Final code must reflect that.

Rewrite `invoke()`:

```php
public static function invoke(API $api, string $peer, string $action, array $map, int $waitSeconds = 15, float $quietSeconds = 2.0): array
{
    $kind = BotScanner::classifyAction($action, $map);
    $callbackAnswer = null;
    $sent = false;
    $before = self::captureTail($api, $peer);

    if ($kind === 'callback') {
        $data = BotScanner::callbackDataFor($action, $map);
        $msgId = BotScanner::msgIdFor($action, $map);
        if ($data === null || $msgId === 0) {
            return self::fail($action, $kind, 'stale_button: run bot.scan then retry');
        }
        try {
            $answer = $api->messages->getBotCallbackAnswer(game: false, peer: $peer, msg_id: $msgId, data: $data);
            $callbackAnswer = ['message' => (string) ($answer['message'] ?? ''), 'alert' => ($answer['_'] ?? '') === 'messages.botCallbackAnswerAlert'];
        } catch (Throwable $e) {
            return self::fail($action, $kind, 'Callback failed: ' . $e->getMessage());
        }
        if ($callbackAnswer['message'] !== '') {
            return self::ok($action, $kind, $sent, '', [], [], null, $callbackAnswer);
        }
    } else {
        try {
            $api->messages->sendMessage(peer: $peer, message: $action);
            $sent = true;
        } catch (Throwable $e) {
            return self::fail($action, $kind, 'Send failed: ' . $e->getMessage());
        }
    }

    $collected = self::collectUntilQuiet(
        static fn() => self::captureTail($api, $peer),
        $before,
        (float) $waitSeconds,
        $quietSeconds,
        self::POLL_INTERVAL,
    );
    $events = $collected['events'];
    $replyMsgId = null;
    foreach (\array_reverse($events) as $e) {
        if ($e['type'] === 'new') {
            $replyMsgId = $e['id'];
            break;
        }
    }
    return self::ok($action, $kind, $sent, $collected['response'], $events, $collected['buttons'], $replyMsgId, $callbackAnswer);
}

private static function captureTail(API $api, string $peer, int $limit = 10): array
{
    try {
        return (array) ($api->messages->getHistory(peer: $peer, limit: $limit, offset_id: 0)['messages'] ?? []);
    } catch (Throwable) {
        return [];
    }
}
```

Update `ok()` signature to `(string $action, string $kind, bool $sent, string $response, array $events, array $buttons, ?int $replyMsgId, ?array $cbAnswer)` emitting keys `action, kind, sent, response, events, buttons(empty→stdClass), reply_msg_id, callback_answer`. Remove old `topIncomingState/pickReply` (superseded) and their tests.

- [ ] **Step 4: Fix all references, suite green**

`grep -rn "pickReply\|topIncomingState" madeline-mcp/{src,tests}` → update/remove (old pickReply test replaced by diffEvents tests).

Run: `vendor/bin/phpunit -c madeline-mcp/phpunit.xml`
Expected: PASS.

- [ ] **Step 5: Live smoke (real BotFather)**

Script `/tmp/opencode/smoke_invoke.php`: require autoloaders (root vendor + spl_autoload for MadelineMcp, copy header from `bin/madeline-assistant`), build ApiClient+ToolCatalog, call `$catalog->call('bot.invoke', ['session_name'=>null,'peer'=>'botfather','action'=>'Madeline Assistant','wait_seconds'=>12])` and print JSON. Expected NOW: `response` = "Good. Now let's choose a username..." (wizard resumes because we send NAME again — acceptable transiently; Task 5 fixes flow). Assert `events` non-empty and `kind==='message'`.

```bash
sudo systemctl restart mcpproxy   # after src change
timeout 90 php /tmp/opencode/smoke_invoke.php
```

- [ ] **Step 6: Commit**

```bash
git add -A madeline-mcp
git commit -m "feat(bots): invoke() returns full event diff (new+edited messages) until quiet window"
```

---

### Task 3: Button freshness guard

**Files:**
- Modify: `madeline-mcp/src/Bots/BotInvoker.php`
- Test: `madeline-mcp/tests/BotScannerTest.php` (pure part)

**Interfaces:**
- Produces: `BotInvoker::buttonStillLive(array $liveMessages, string $text, string $b64Data): bool` (pure: does ANY message carry this exact callback data under this text?). invoke() callback path: before pressing, fetch tail once, if guard fails → `self::fail(...,'stale_button: re-run bot.scan then retry')`.

- [ ] **Step 1: Failing test**

```php
public function testButtonStillLive(): void
{
    $live = [['id' => 9, 'out' => false, 'reply_markup' => ['rows' => [['buttons' => [
        ['_' => 'keyboardButtonCallback', 'text' => 'Next', 'data' => 'pg3']]]]]]];
    $this->assertTrue(BotInvoker::buttonStillLive($live, 'Next', base64_encode('pg3')));
    $this->assertFalse(BotInvoker::buttonStillLive($live, 'Next', base64_encode('pg2'))); // edited away
}
```

- [ ] **Step 2: Red → implement → green**

Implementation:

```php
public static function buttonStillLive(array $liveMessages, string $text, string $b64Data): bool
{
    foreach ($liveMessages as $m) {
        if (!\is_array($m) || ($m['out'] ?? false) !== false) {
            continue;
        }
        $b = BotScanner::buttonsOfMessage($m)[$text] ?? null;
        if (\is_array($b) && ($b['type'] ?? '') === 'callback' && ($b['data'] ?? '') === $b64Data) {
            return true;
        }
    }
    return false;
}
```

Wire into `invoke()` callback branch BEFORE getBotCallbackAnswer:

```php
if (!self::buttonStillLive(self::captureTail($api, $peer), $action, $data)) {
    return self::fail($action, $kind, 'stale_button: menu was re-rendered; run bot.scan then retry');
}
```

Run: full suite green.

- [ ] **Step 3: Commit**

```bash
git add -A madeline-mcp && git commit -m "feat(bots): freshness guard rejects presses of callback data that no longer exists"
```

---

### Task 4: `bot.read` tool + sanitizer projection for new shapes

**Files:**
- Modify: `madeline-mcp/src/Bots/BotCatalog.php` (tool def + dispatch + `read()`)
- Modify: `madeline-mcp/src/ResponseSanitizer.php` (cases `bot.invoke`, `bot.read`)
- Test: `madeline-mcp/tests/SanitizerTest.php`

**Interfaces:**
- Consumes: `BotScanner::fromHistory/buttonsOfMessage`, `BotCatalog::readJson/cacheFile/botKey/sessionOf`.
- Produces:
  - MCP tool `bot.read` schema `{session_name?, peer}` → `{peer,title?,username?,messages:[{id,out,text≤500,inline_buttons}],inline_buttons:{...full meta},scanned_at}`; refreshes cached map's `inline_buttons` (merge) without touching `commands`.
  - Sanitizer projections: `bot.invoke` keeps `{action,kind,sent,response≤1500,events:[{id,type,text≤300,n_buttons}],buttons,reply_msg_id,callback_answer}`; `bot.read` keeps `{peer,messages:[{id,out,text≤300,n_buttons}],inline_buttons}`.

- [ ] **Step 1: Failing sanitizer tests**

In `SanitizerTest.php` add:

```php
public function testInvokeProjectionTruncatesEvents(): void
{
    $in = json_encode([
        'action' => '/menu', 'kind' => 'command', 'sent' => true,
        'response' => str_repeat('x', 5000),
        'events' => [['id' => 1, 'type' => 'new', 'text' => str_repeat('y', 900), 'buttons' => ['A' => []]], ['id' => 1, 'type' => 'edit', 'text' => 'v2', 'buttons' => []]],
        'buttons' => ['A' => ['type' => 'callback']],
        'reply_msg_id' => 1, 'callback_answer' => null,
    ]);
    $out = ResponseSanitizer::sanitize('bot.invoke', $in);
    $d = json_decode($out, true);
    $this->assertLessThanOrEqual(1600, mb_strlen($d['response']));
    $this->assertSame('edit', $d['events'][1]['type']);
    $this->assertSame(1, $d['events'][1]['n_buttons']);
    $this->assertArrayNotHasKey('data', $d['buttons']['A']); // payloads stay server-side
}

public function testReadProjection(): void
{
    $in = json_encode(['peer' => '@bf', 'messages' => [['id' => 5, 'out' => false, 'text' => str_repeat('z', 900), 'inline_buttons' => ['Go' => []]]], 'inline_buttons' => ['Go' => ['type' => 'callback', 'data' => 'SECRET', 'msg_id' => 5]]]);
    $d = json_decode(ResponseSanitizer::sanitize('bot.read', $in), true);
    $this->assertLessThanOrEqual(301, mb_strlen($d['messages'][0]['text']));
    $this->assertSame(1, $d['messages'][0]['n_buttons']);
    $this->assertSame('SECRET', $d['inline_buttons']['Go']['data']); // read NEEDS data for pressing
}
```

Note: `bot.read.inline_buttons` KEEPS `data` (the AI needs it only implicitly — pressing goes by button TEXT; data stays server-side in cache. So actually strip `data` here too; adjust assertion to assertArrayNotHasKey('data', ...) — FINAL DECISION: strip, matching bot.invoke; pressing resolves data server-side from cache.)

- [ ] **Step 2: Red → implement projections → green**

In `ResponseSanitizer::project()` add cases:

```php
case 'bot.read':
    $d['messages'] = array_map(static fn($m) => [
        'id' => $m['id'] ?? 0,
        'out' => (bool) ($m['out'] ?? false),
        'text' => mb_substr((string) ($m['text'] ?? ''), 0, 300),
        'n_buttons' => is_array($m['inline_buttons'] ?? null) ? count($m['inline_buttons']) : 0,
    ], (array) ($d['messages'] ?? []));
    if (isset($d['inline_buttons']) && is_array($d['inline_buttons'])) {
        foreach ($d['inline_buttons'] as &$b) { unset($b['data']); }
        unset($b);
    }
    break;
case 'bot.invoke':
    $d['response'] = mb_substr((string) ($d['response'] ?? ''), 0, 1500);
    $d['events'] = array_map(static fn($e) => [
        'id' => $e['id'] ?? 0,
        'type' => $e['type'] ?? '',
        'text' => mb_substr((string) ($e['text'] ?? ''), 0, 300),
        'n_buttons' => is_array($e['buttons'] ?? null) ? count($e['buttons']) : 0,
    ], (array) ($d['events'] ?? []));
    unset($d['buttons']); // canonical buttons already deduped server-side into cache; AI uses bot.read for current menus
    break;
```

Implement `BotCatalog::read()`:

```php
private function read(array $args, ApiClient $client): array
{
    $peer = (string) ($args['peer'] ?? '');
    if ($peer === '') {
        return ['_error' => true, 'message' => 'peer required'];
    }
    $session = $this->sessionOf($args, $client);
    $key = $this->botKey($client, $session, $peer);
    $file = $this->cacheFile($session, $key);
    try {
        $info = $client->api($session)->getInfo($peer);
        $u = (array) ((array) ($info['User'] ?? $info['user'] ?? []));
        $username = (string) ($u['username'] ?? '');
        $title = (string) ($u['title'] ?? ($u['first_name'] ?? $peer));
    } catch (Throwable) {
        $username = '';
        $title = $peer;
    }
    try {
        $h = (array) $client->api($session)->messages->getHistory(peer: $peer, limit: 10, offset_id: 0);
    } catch (Throwable $e) {
        return ['_error' => true, 'message' => 'getHistory failed: ' . $e->getMessage()];
    }
    $msgs = (array) ($h['messages'] ?? []);
    $outMsgs = [];
    $allBtns = [];
    foreach ($msgs as $m) {
        if (!\is_array($m)) {
            continue;
        }
        $btns = BotScanner::buttonsOfMessage($m);
        foreach ($btns as $t => $meta) {
            $allBtns[$t] = $meta;
        }
        $outMsgs[] = ['id' => (int) ($m['id'] ?? 0), 'out' => (bool) ($m['out'] ?? false), 'text' => (string) ($m['message'] ?? ''), 'inline_buttons' => $btns];
    }
    // merge into cached map (keep commands etc.)
    $map = $this->readJson($file) ?? [];
    foreach ($allBtns as $t => $meta) {
        $map['inline_buttons'][$t] = $meta;
    }
    if (!\is_dir(\dirname($file))) {
        \mkdir(\dirname($file), 0755, true);
    }
    \file_put_contents($file, \json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return ['peer' => $username !== '' ? '@' . $username : $peer, 'title' => $title, 'username' => $username, 'messages' => $outMsgs, 'inline_buttons' => $allBtns ?: new \stdClass(), 'scanned_at' => \time()];
}
```

Register tool def in `tools()`: `'name' => 'bot.read', description 'Fresh transcript tail + current inline buttons for a peer (observe without acting).'`, schema `{peer, session_name?}`, and route in `dispatch()` match: `'bot.read' => $this->read($args, $client)`; include in `has()`.

- [ ] **Step 3: Restart proxy, approve quarantined tool**

```bash
sudo systemctl restart mcpproxy
mcpproxy tools list 2>/dev/null | grep -i quarantine || true
mcpproxy tools approve "madeline-mcp:bot.read"   # adjust flags to CLI output if different
```

- [ ] **Step 4: Suite + commit**

Full suite green.

```bash
git add -A madeline-mcp && git commit -m "feat(bots): bot.read observe primitive; compact sanitizer projections for invoke/read"
```

---

### Task 5: Stateless ensure() over primitives (fixes wizard desync)

**Files:**
- Modify: `madeline-mcp/bin/madeline-assistant` (replace ensure action)
- Test: none unit (network-driven; covered by Task 6 E2E)

**Interfaces:**
- Consumes: ToolCatalog `bot.invoke`, `bot.read` (owner session, peer `botfather`).
- Produces: `ensure` idempotent across reruns/crashes; artifacts `cache/assistant-state.json` `{owner_id, session:'madeline-assistant', username}` and suppress map via `AssistantBot::writeSuppressMap`.

Wizard classifier (regexes on LAST BotFather incoming text):

| Last incoming matches | Meaning | Next action |
|---|---|---|
| `/Done! Congratulations/i` + token regex | created | capture token, stop |
| `/choose a username/i` OR `/invalid/i` OR `/taken/i` | awaiting username | send USERNAME |
| `/How are we going to call it/i` | awaiting name | send NAME |
| anything else / no wizard | idle | send `/newbot` |

USERNAME sent lowercase (`strtolower`) — BotFather rejected mixed-case earlier.

- [ ] **Step 1: Implement loop**

Replace ensure case body (keep login/suppress sections from current file — they work):

```php
$username = strtolower(getenv('MADELINE_ASSISTANT_USERNAME') ?: AssistantBot::DEFAULT_USERNAME);
$api = new ApiClient();
$me = $api->api()->getSelf();
$ownerId = (int) $me['id'];
$token = null;
for ($step = 0; $step < 8 && $token === null; $step++) {
    $r = bfInvoke($api, ['action' => 'bot.read', ...]) // WRONG tool args; use direct:
    // read last incoming:
    $rd = json_decode((string) (new MadelineMcp\ToolCatalog($api))->call('bot.read', ['session_name' => null, 'peer' => BF]), true) ?: [];
    $last = '';
    foreach (($rd['messages'] ?? []) as $m) {
        if (($m['out'] ?? true) === false) { $last = (string) ($m['text'] ?? ''); break; }
    }
    if (preg_match('/Done! Congratulations/i', $last) && preg_match($TOKEN_RE, json_encode($rd), $mtok)) { $token = $mtok[1]; break; }
    if (preg_match('/How are we going to call it/i', $last)) { $act = AssistantBot::BOT_NAME; }
    elseif (preg_match('/username/i', $last)) { $act = $username; }
    else { $act = '/newbot'; }
    out("step {$step}: state='" . mb_substr($last, 0, 60) . "' -> '" . $act . "'");
    $inv = bfInvoke($api, ['action' => $act]);
    out('BF: ' . mb_substr(bfReplyText($inv), 0, 140));
}
if ($token === null) { fail('wizard did not complete; inspect botfather chat'); }
out("token captured");
```

NOTE for implementer: `bot.read` messages are newest-first; take FIRST non-out entry. Token also appears inside `bot.invoke` events when success arrives — merge both texts before token regex (search `$last` + `json_encode($inv)`).

Keep existing post-token block verbatim (exists-check short-circuit removed — wizard IS the existence check now; if bot exists globally getInfo succeeds and wizard hits `/mybots` API-Token recovery instead — KEEP that existing recovery branch unchanged, placed BEFORE the wizard loop).

- [ ] **Step 2: Dry-run classifier offline (no sends)**

Extract classifier into `function classifyWizard(string $last): string` returning one of `token|name|username|newbot`; quick `php -r` checks against the four known BotFather strings from history.

- [ ] **Step 3: Commit**

```bash
git add madeline-mcp/bin/madeline-assistant && git commit -m "feat(assistant): stateless ensure driven by visible BotFather wizard state"
```

---

### Task 6: LIVE end-to-end bot creation

**Files:** none new (operational task)

- [ ] **Step 1: Run ensure**

```bash
cd /home/merezarezaei/Documents/projects/MadelineProto && timeout 180 php madeline-mcp/bin/madeline-assistant ensure 2>&1 | grep -vE '^Logger|^Serialization'
```
Expected: steps walk name → username → Done! Congratulations; token captured; bot session logged in ("Logged in as bot"); suppress map written `501558149<-><bot_id>`; final line "Done."

- [ ] **Step 2: Verify session + ownership**

```bash
php -r 'require "vendor/autoload.php"; spl_autoload_register(function($c){if(str_starts_with($c,"MadelineMcp\\"))
{require "madeline-mcp/src/".str_replace("\\\\","/",substr($c,13)).".php";}};
$a=new MadelineMcp\ApiClient(); var_export($a->api("madeline-assistant")->getSelf());'
```
Expected: `['username'=>'madeline_501558149_bot', ...]`.

- [ ] **Step 3: Commit state-free code only (state files are in gitignored cache/) & push**

```bash
git push fork v8
```

---

### Task 7: Assistant runtime v2 — diff-based inbox + agent loop polish

**Files:**
- Modify: `madeline-mcp/src/Assistant/AssistantBot.php` (replace `fetchNewIncoming` with snapshot/diff; keep agent loop)
- Modify: `madeline-mcp/src/Assistant/OmniClient.php`, `ToolBridge.php` (only fixes surfaced here)
- Test: `madeline-mcp/tests/AssistantTest.php` (extend)

**Interfaces:**
- Consumes: `BotInvoker::snapshot/diffEvents` (Task 1) applied to OWNER peer from BOT session; `ToolBridge::openaiTools()/call()`; `OmniClient::chat()`.
- Produces: `run()` daemon; `handleText(int ownerId, string $text): string` unchanged signature.

- [ ] **Step 1: Replace polling internals**

`fetchNewIncoming(object $botApi, int $peerId)` becomes:

```php
private function fetchNewIncoming(object $botApi, int $peerId): array
{
    $tail = [];
    try {
        $tail = (array) ($botApi->messages->getHistory(peer: $peerId, limit: 5, offset_id: 0)['messages'] ?? []);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach (BotInvoker::incomingOnly($tail) as $m) {
        $id = (int) ($m['id'] ?? 0);
        if ($id <= $this->lastIncomingId) {
            continue;
        }
        $this->lastIncomingId = $id;
        $text = \trim((string) ($m['message'] ?? ''));
        if ($text !== '') {
            $out[] = $m;
        }
    }
    return $out;
}
```

(`incomingOnly` already drops outgoing/service — loop-guard inherited.)

- [ ] **Step 2: Unit-test chunker edge + history trim (extend AssistantTest)**

```php
public function testChunkExactBoundary(): void
{
    $t = str_repeat('a', 4096);
    $this->assertCount(1, AssistantBot::chunkText($t));
    $this->assertCount(2, AssistantBot::chunkText($t . 'b'));
}
```

- [ ] **Step 3: Suite + commit**

```bash
vendor/bin/phpunit -c madeline-mcp/phpunit.xml && git add -A madeline-mcp && git commit -m "refactor(assistant): inbox detection via transcript diff primitives"
```

---

### Task 8: LIVE assistant E2E (OmniRoute round-trip with tool use)

**Files:** operational

- [ ] **Step 1: Start daemon in background**

Use pty_spawn (long-running): `php /home/merezarezaei/Documents/projects/MadelineProto/madeline-mcp/bin/madeline-assistant run`
Expected first log: `Assistant online. owner=501558149 session=madeline-assistant`.

- [ ] **Step 2: Send probe from OWNER account natively**

Via native mcpproxy meta-tool (NOT terminal): `madeline-mcp:send_message` `{peer:"@madeline_501558149_bot", message:"How many chats do I have? Use your tools."}`
Then `madeline-mcp:resolve_peer`/history check: assistant reply should arrive ≤60s and reference real numbers (it will have called e.g. `bots.list`/`session.get_quota`/`list_dialogs` through the bridge).

- [ ] **Step 3: Loop-prevention proof**

From OWNER, send second probe; confirm exactly ONE reply per message and NO reply storms (assistant ignores its own outgoing; owner-side handlers don't exist yet, suppress map documented for them).

- [ ] **Step 4: README + memory + push**

README section: architecture philosophy paragraph, `bot.read`, event-diff invoke, freshness guard, ensure/run usage, OmniRoute config path, systemd/nohup hint. Save Nowledge memories: bridge-v2 semantics; assistant stack.

```bash
git add -A && git commit -m "docs: bot bridge v2 + assistant architecture" && git push fork v8
```

---

## Self-Review Notes

- Spec coverage: event-diff invoke ✓(T1,T2), freshness guard ✓(T3), bot.read ✓(T4), sanitizer ✓(T4), stateless ensure ✓(T5), creation E2E ✓(T6), assistant runtime + loop-guards ✓(T7), live agent E2E ✓(T8). Deletions tracking intentionally omitted (YAGNI — rare for bots).
- Type consistency: `events` element `{id:int,type:string,text:string,buttons:array}` used identically in T1/T2/T4. `ok()` signature updated once in T2 and referenced nowhere else.
- Placeholder scan: T5 contains real classifier table + loop code; T2 notes the double-fetch pitfall explicitly.
