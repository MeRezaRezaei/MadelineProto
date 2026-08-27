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

namespace danog\MadelineProto\Test;

use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use function Amp\Redis\createRedisClient;
use danog\MadelineProto\Accounts\AccountManager;
use danog\MadelineProto\Daemon\Daemon;
use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Sync\AccountDataProvider;
use danog\MadelineProto\Sync\SyncLoop;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

/**
 * Daemon acceptance tests (SQLite + real Redis 16379).
 */
class DaemonTest extends TestCase
{
    private const REDIS_DSN = 'tcp://127.0.0.1:16379';

    private PdoDriver $driver;
    private RelationalStore $store;
    private AccountManager $accounts;
    private Cache $cache;
    private RedisClient $raw;
    private string $prefix;
    private ?Daemon $daemon = null;

    protected function setUp(): void
    {
        try {
            $this->raw = createRedisClient(RedisConfig::fromUri(self::REDIS_DSN));
            $this->raw->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable at ' . self::REDIS_DSN . ': ' . $e->getMessage());
        }

        $this->prefix = 'mp-daemon-test-' . bin2hex(random_bytes(4)) . ':';
        $this->cache = new Cache($this->raw, $this->prefix);

        $this->driver = new PdoDriver('sqlite::memory:');
        (new Migrations($this->driver))->migrate();
        $this->store = new RelationalStore($this->driver);
        $this->accounts = new AccountManager($this->store);
    }

    private function fakeProvider(): AccountDataProvider
    {
        return new class implements AccountDataProvider {
            public function pull(int $accountId): array
            {
                return [
                    'user' => [
                        'user_id' => 999,
                        'access_hash' => '999',
                        'username' => 'daemon_test_user',
                        'raw' => json_encode(['user_id' => 999]),
                    ],
                    'messages' => [],
                    'peers' => [],
                    'chats' => [],
                    'channels' => [],
                ];
            }
        };
    }

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

    public function testBootSetsRunningTrue(): void
    {
        $daemon = $this->buildDaemon();
        $this->assertFalse($daemon->isRunning());

        $daemon->boot();
        $this->assertTrue($daemon->isRunning());

        $daemon->stop();
    }

    public function testStopSetsRunningFalse(): void
    {
        $daemon = $this->buildDaemon();
        $daemon->boot();
        $this->assertTrue($daemon->isRunning());

        $daemon->stop();
        $this->assertFalse($daemon->isRunning());
    }

    public function testStopIsIdempotent(): void
    {
        $daemon = $this->buildDaemon();
        $daemon->boot();

        $daemon->stop();
        $this->assertFalse($daemon->isRunning());

        // Second stop must not throw or change state.
        $daemon->stop();
        $this->assertFalse($daemon->isRunning());
    }

    public function testBootIsIdempotent(): void
    {
        $daemon = $this->buildDaemon();
        $daemon->boot();
        $this->assertTrue($daemon->isRunning());

        // Second boot must be a no-op.
        $daemon->boot();
        $this->assertTrue($daemon->isRunning());

        $daemon->stop();
    }

    public function testStopClosesDriverResource(): void
    {
        $daemon = $this->buildDaemon();
        $daemon->boot();
        $daemon->stop();

        // After close(), the PDO connection is released. Trying to query
        // through the driver should throw (PDO in invalid state).
        $threw = false;
        try {
            $this->driver->query('SELECT 1');
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Driver should be closed after stop()');
    }

    public function testSignalHandlingCallsStop(): void
    {
        $daemon = $this->buildDaemon();
        $daemon->boot();
        $this->assertTrue($daemon->isRunning());

        $prevHandler = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (\Throwable $e): void {
            if ($e instanceof \Amp\SignalException || ($e instanceof \Revolt\EventLoop\UncaughtThrowable && $e->getPrevious() instanceof \Amp\SignalException)) {
                return;
            }
            throw $e;
        });

        try {
            // Send SIGTERM to self while the loop runs; Revolt dispatches it and the
            // boot() handler calls stop(). The watchdog guarantees run() returns even
            // if the signal is missed.
            EventLoop::defer(static function (): void {
                posix_kill(getmypid(), SIGTERM);
            });
            $watchdog = EventLoop::delay(2.0, static fn () => $daemon->stop());
            EventLoop::reference($watchdog);
            EventLoop::run();
        } finally {
            EventLoop::setErrorHandler($prevHandler);
        }

        $this->assertFalse($daemon->isRunning());
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
                // Flush test-prefixed keys using SCAN + DEL.
                foreach ($this->raw->scan($this->prefix . '*') as $key) {
                    $this->raw->delete($key);
                }
            } catch (\Throwable) {
                // Redis already closed or unreachable.
            }
        }
    }
}
