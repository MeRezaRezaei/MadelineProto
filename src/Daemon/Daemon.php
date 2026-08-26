<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Daemon;

use danog\MadelineProto\Accounts\AccountManager;
use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\SqlDriver;
use danog\MadelineProto\Sync\SyncLoop;
use Revolt\EventLoop;

/**
 * Systemd-managed daemon that owns all account sessions and backing stores.
 *
 * Replaces the fragile proc_open IPC worker — the zombie risk is eliminated
 * because systemd reap is handled at the init level.
 *
 * All dependencies are injected (no hardcoded DSN/port).  Both boot() and
 * stop() are idempotent.
 */
final class Daemon
{
    private bool $running = false;

    /** @var list<string> EventLoop signal-watcher ids, cancelled on stop(). */
    private array $signalWatchers = [];

    public function __construct(
        private readonly SqlDriver $driver,
        private readonly Cache $cache,
        private readonly AccountManager $accounts,
        private readonly SyncLoop $sync,
    ) {
    }

    /**
     * Start the daemon: launch the sync loop and install signal handlers
     * so that SIGTERM / SIGINT trigger a clean shutdown.
     */
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

    /**
     * Gracefully stop the daemon: halt the sync loop and release all
     * backing-store resources.  Idempotent — safe to call multiple times.
     */
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

    public function isRunning(): bool
    {
        return $this->running;
    }
}
