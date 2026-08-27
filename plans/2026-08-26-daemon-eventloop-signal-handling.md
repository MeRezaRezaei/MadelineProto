# Daemon Event-Loop & Signal-Handling End-to-End Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the MadelineProto daemon actually run its sync loop and shut down cleanly on SIGTERM/SIGINT, and prove it with a green test suite.

**Architecture:** The daemon `SyncLoop` is driven by `danog\Loop\PeriodicLoop`, which only ticks when the **Revolt event loop** (`Revolt\EventLoop`) is running via `EventLoop::run()`. The current `bin/madeline-daemon` blocks in a `while/usleep` busy loop and never calls `EventLoop::run()`, so the sync loop never ticks in production. Signal handling currently uses `pcntl_signal` (unreliable inside Amp/Revolt). Fix: drive the loop with `EventLoop::run()`, register signals with `EventLoop::onSignal`, and have `stop()` cancel all watchers so the loop returns.

**Tech Stack:** PHP 8.1+, `amphp/redis`, `amphp/amp` (`Amp\Future`/`async`/`await`), `revolt/event-loop` (`Revolt\EventLoop`), `danog/loop` (`danog\Loop\PeriodicLoop`), PHPUnit. DB via `PdoDriver` (sqlite in tests, sqlite/postgres in prod). Redis at `tcp://127.0.0.1:16379`.

**Spec:** This plan implements the daemon runtime correctness fix discovered during code review of branch `v8` (tasks 7–9: Redis event bus, Daemon, SyncLoop). No separate spec doc exists; this plan is the source of truth.

## Global Constraints

- Every PHP file begins with `declare(strict_types=1);` and the AGPL license header used by existing files (copy from `src/Daemon/Daemon.php:1-15`).
- Code lives under namespace `danog\MadelineProto\*`; tests under `danog\MadelineProto\Test`.
- Async methods return `Amp\Future`; use `Amp\async()` / `->await()` — never block the event loop with `sleep`/`usleep`.
- `danog\Loop\PeriodicLoop` callback semantics: **return `false` (or any non-`true`) to keep looping; return `true` to stop.** (verified in `vendor/danog/loop/lib/PeriodicLoop.php:41`)
- `Revolt\EventLoop` is a **process-global singleton**: every registered watcher (signal, repeat, delay) stays alive across PHPUnit test methods until explicitly cancelled. Always pair `boot()` with `stop()`; never leave a `PeriodicLoop` running between tests.
- Tests that need Redis connect to `tcp://127.0.0.1:16379` (no auth) and mark themselves skipped if unreachable; use `sqlite::memory:` for the DB.

---

### Task 1: Drive the daemon with Revolt signals instead of `pcntl_signal`

**Files:**
- Modify: `src/Daemon/Daemon.php`
- Test: `tests/Daemon/DaemonTest.php` (existing tests must still pass)

**Interfaces:**
- Consumes: `danog\MadelineProto\Sync\SyncLoop::start()`, `::stop()`; `danog\MadelineProto\Db\SqlDriver::close()`.
- Produces: `Daemon::boot()` (installs SIGTERM/SIGINT watchers via `EventLoop::onSignal`), `Daemon::stop()` (cancels watchers + stops sync + closes driver).

- [x] **Step 1: Write/confirm the failing behavior**

The current `Daemon::boot()` uses `pcntl_signal`, which is not driven by the event loop. Confirm the runtime symptom by reading `src/Daemon/Daemon.php` and noting `boot()` installs `pcntl_signal` handlers and `stop()` never cancels them.

- [x] **Step 2: Replace the signal registration in `boot()`**

In `src/Daemon/Daemon.php`, add `use Revolt\EventLoop;` (already imported in current branch) and replace the `pcntl_signal` block. The class gains a property to hold watcher ids and `boot()`/`stop()` become:

```php
// property on the class
/** @var list<string> EventLoop signal-watcher ids, cancelled on stop(). */
private array $signalWatchers = [];

public function boot(): void
{
    if ($this->running) {
        return;
    }

    $this->running = true;
    $this->sync->start();

    $daemon = $this;
    $this->signalWatchers[] = EventLoop::onSignal(\SIGTERM, static function () use ($daemon): void {
        $daemon->stop();
    });
    $this->signalWatchers[] = EventLoop::onSignal(\SIGINT, static function () use ($daemon): void {
        $daemon->stop();
    });
}

public function stop(): void
{
    if (!$this->running) {
        return;
    }

    $this->running = false;
    $this->sync->stop();
    foreach ($this->signalWatchers as $watcherId) {
        EventLoop::cancel($watcherId);
    }
    $this->signalWatchers = [];
    $this->driver->close();
}
```

- [x] **Step 3: Run the existing daemon tests to confirm still green**

Run: `vendor/bin/phpunit --no-coverage --filter DaemonTest tests/Daemon/DaemonTest.php`
Expected: PASS for `testBootSetsRunningTrue`, `testStopSetsRunningFalse`, `testStopIsIdempotent`, `testBootIsIdempotent`, `testStopClosesDriverResource`. (Signal test is fixed in Task 3.)

- [x] **Step 4: Commit**

```bash
git add src/Daemon/Daemon.php
git commit -m "fix(daemon): handle SIGTERM/SIGINT via Revolt EventLoop::onSignal"
```

---

### Task 2: Drive the event loop in the CLI entry point

**Files:**
- Modify: `bin/madeline-daemon`

**Interfaces:**
- Consumes: `Daemon::boot()` (now installs loop-driven signal watchers), `Daemon::stop()` (cancels all watchers).
- Produces: A runnable `bin/madeline-daemon start` that keeps the sync loop alive until a signal stops it, then cleans up the PID file.

- [x] **Step 1: Replace the busy-wait loop with `EventLoop::run()`**

Add `use Revolt\EventLoop;` and replace the terminal block at the end of `bin/madeline-daemon`. The new tail of the file:

```php
// Write PID file.
file_put_contents($pidFile, (string) getmypid());

// Daemon::boot() installs signal watchers (via Revolt's EventLoop::onSignal);
// EventLoop::run() drives the sync loop and dispatches signals. It returns
// once the daemon stops (all referenced watchers cancelled in stop()).
$daemon->boot();
EventLoop::run();

// Clean up the PID file after a clean shutdown.
@unlink($pidFile);
exit(0);
```

Remove the old `while ($daemon->isRunning()) { pcntl_signal_dispatch(); usleep(200_000); }` block.

- [x] **Step 2: Lint the script**

Run: `php -l bin/madeline-daemon`
Expected: `No syntax errors detected`

- [x] **Step 3: Commit**

```bash
git add bin/madeline-daemon
git commit -m "fix(daemon): run the Revolt event loop so the sync loop actually ticks"
```

---

### Task 3: Make `DaemonTest` loop-safe (no hangs, no cross-test leakage)

**Files:**
- Modify: `tests/Daemon/DaemonTest.php`

**Interfaces:**
- Consumes: `Daemon::boot()`, `Daemon::stop()`, `Revolt\EventLoop::run()`, `Revolt\EventLoop::onSignal`, `Revolt\EventLoop::delay`, `Revolt\EventLoop::cancel`.
- Produces: A deterministic signal test that returns within a bounded time and asserts `isRunning() === false` after SIGTERM; a `tearDown` that guarantees no leaked watchers.

- [x] **Step 1: Add a watchdog so `EventLoop::run()` can never block forever**

Update `testSignalHandlingCallsStop()` to drive the loop and bound its runtime with a watchdog delay that force-stops the daemon if the signal is missed:

```php
public function testSignalHandlingCallsStop(): void
{
    $daemon = $this->buildDaemon();
    $daemon->boot();
    $this->assertTrue($daemon->isRunning());

    // Send SIGTERM to self; Revolt dispatches it while the loop runs and the
    // boot() handler calls stop(). The watchdog guarantees run() returns even
    // if the signal is missed.
    posix_kill(getmypid(), SIGTERM);
    $watchdog = EventLoop::delay(2.0, static fn () => $daemon->stop());
    EventLoop::reference($watchdog);
    EventLoop::run();

    $this->assertFalse($daemon->isRunning());
}
```

- [x] **Step 2: Guarantee no leaked watchers between tests**

Ensure `tearDown()` stops any running daemon so a `PeriodicLoop` from a prior test can never keep a later `EventLoop::run()` alive. Store the daemon on the test instance and stop it in `tearDown`. Update `buildDaemon()` to assign `$this->daemon`, and update `tearDown()`:

```php
private ?Daemon $daemon = null;

private function buildDaemon(): Daemon
{
    $sync = new SyncLoop(
        $this->accounts,
        $this->store,
        $this->cache,
        $this->fakeProvider(),
        30
    );

    return $this->daemon = new Daemon($this->driver, $this->cache, $this->accounts, $sync);
}

protected function tearDown(): void
{
    if (isset($this->daemon) && $this->daemon->isRunning()) {
        $this->daemon->stop();
    }
    if (isset($this->driver)) {
        try {
            $this->driver->close();
        } catch (\Throwable) {
            // Already closed.
        }
    }
    if (isset($this->raw)) {
        try {
            foreach ($this->raw->scan($this->prefix . '*') as $key) {
                $this->raw->delete($key);
            }
        } catch (\Throwable) {
            // Redis already closed or unreachable.
        }
    }
}
```

- [x] **Step 3: Run ONLY the signal test first to confirm it does not hang**

Run: `timeout 30 vendor/bin/phpunit --no-coverage --filter testSignalHandlingCallsStop tests/Daemon/DaemonTest.php`
Expected: PASS within a few seconds (no 120s hang).

- [x] **Step 4: Run the full DaemonTest class**

Run: `timeout 120 vendor/bin/phpunit --no-coverage --filter DaemonTest tests/Daemon/DaemonTest.php`
Expected: all 6 tests PASS.

- [x] **Step 5: Commit**

```bash
git add tests/Daemon/DaemonTest.php
git commit -m "test(daemon): drive event loop with watchdog; stop daemon in tearDown"
```

---

### Task 4: Run the broader async test suite to green

**Files:**
- Run: `tests/Sync/SyncLoopTest.php`, `tests/Db/*`, `tests/Events/*` (sanity)

**Interfaces:**
- Consumes: the fixed `Daemon`, `SyncLoop`, `EventBus`, `CachedStore`, `RelationalStore`, `Cache`.
- Produces: confidence that the event-loop change did not regress sibling components.

- [x] **Step 1: Run SyncLoopTest**

Run: `timeout 120 vendor/bin/phpunit --no-coverage tests/Sync/SyncLoopTest.php`
Expected: PASS (SyncLoop uses `tick()` directly, no loop dependency, but confirms no API break).

- [x] **Step 2: Run the Db and Events suites**

Run: `timeout 180 vendor/bin/phpunit --no-coverage tests/Db tests/Events`
Expected: PASS.

- [x] **Step 3: Commit nothing new — only note results**

If any test fails due to the change, return to the relevant Task and fix. Otherwise proceed.

---

### Task 5 (optional): Live `bin/madeline-daemon` smoke run

**Files:**
- Run: `bin/madeline-daemon` (no code change unless a bug surfaces)

**Interfaces:**
- Consumes: a reachable DB DSN (`--dsn`), Redis (`--redis`), and the migrations + `SyncLoop` provider wiring already in the script.
- Produces: proof that `start` keeps the process alive, `status` reports running, and `stop` (SIGTERM) shuts it down and removes the PID file.

- [x] **Step 1: Start the daemon against a file sqlite DB + Redis**

Run: `php bin/madeline-daemon start --dsn="sqlite:/tmp/mp-smoke.db" --redis="tcp://127.0.0.1:16379" > /tmp/mp-daemon.log 2>&1 &`
Expected: process stays alive; `/tmp/madeline-daemon.pid` exists.

- [x] **Step 2: Check status**

Run: `php bin/madeline-daemon status`
Expected: `Daemon running (PID <n>)`

- [x] **Step 3: Stop and confirm clean shutdown**

Run: `php bin/madeline-daemon stop`
Expected: prints `Sent SIGTERM...`, then `PID <n> exited`, and `/tmp/madeline-daemon.pid` is removed. `cat /tmp/mp-daemon.log` shows no fatal errors.

- [x] **Step 4: Commit only if a code fix was required**

```bash
git add -A
git commit -m "fix(daemon): <describe the smoke-run fix>"
```

---

## Self-Review Notes

- **Spec coverage:** Core runtime bug (loop never driven) → Task 1 + Task 2. Signal correctness → Task 1 + Task 3. Test green → Task 3 + Task 4. Optional live proof → Task 5.
- **Placeholder scan:** No "TBD"/"handle edge cases" left; every code step contains the actual code.
- **Type consistency:** `Daemon::boot()`/`stop()` signatures unchanged; `$signalWatchers` is `list<string>` matching `EventLoop::onSignal()`'s returned string id and `EventLoop::cancel(string)`'s parameter. `buildDaemon()` still returns `Daemon` and now also assigns `$this->daemon`.
- **Leakage guard:** Task 3 `tearDown` stops the daemon; every existing test already pairs `boot()` with `stop()`, so no `PeriodicLoop` survives between methods.
