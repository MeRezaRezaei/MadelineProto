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

namespace danog\MadelineProto\Events;

use Amp\Redis\Command\Option\SetOptions;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisSubscriber;
use Amp\Redis\RedisSubscription;
use Amp\Redis\Connection\RedisConnector;
use Revolt\EventLoop;
use RuntimeException;
use function Amp\Redis\createRedisClient;
use function Amp\Redis\createRedisConnector;

/**
 * Redis-backed event bus: one dispatcher, many accounts, fan-in via pub/sub.
 *
 * Accounts publish updates through the publisher connection.
 * The dispatcher subscribes on a separate connection and dispatches
 * to registered listeners whose filter matches.
 *
 * Design for testability: inject RedisClient / RedisConnector instances
 * (or DSN strings) so no real network is needed in unit tests.
 */
final class EventBus
{
    private const CHANNEL_UPDATES = 'madeline:updates';
    private const DEDUP_PREFIX = 'madeline:dedup:';
    private const DEDUP_DEFAULT_MESSAGE_TTL = 3600;
    private const DEDUP_DEFAULT_SERVICE_TTL = 300;

    /** @var array<string, list<array{callable, array}>> */
    private array $listeners = [];

    /** @var array<string, int> In-memory seen-set for fast local dedup (value = expiry timestamp). */
    private array $seenKeys = [];

    private ?RedisSubscription $subscription = null;
    private bool $running = false;

    private readonly RedisClient $publisherConn;
    private readonly RedisSubscriber $subscriberConn;

    /**
     * @param RedisClient|string    $publisher          Connection for publishing (accounts emit here).
     * @param RedisConnector|string $subscriber         Connector or DSN for the blocking subscription.
     * @param array<string,int>     $deduplicationTtl   TTL per update type (seconds).
     */
    public function __construct(
        RedisClient|string $publisher,
        RedisConnector|string $subscriber,
        private readonly array $deduplicationTtl = ['messages' => 3600, 'service' => 300],
    ) {
        $this->publisherConn = \is_string($publisher)
            ? $this->createClient($publisher)
            : $publisher;

        $connector = \is_string($subscriber)
            ? createRedisConnector(RedisConfig::fromUri($subscriber))
            : $subscriber;

        $this->subscriberConn = new RedisSubscriber($connector);
    }

    /**
     * Compute a stable dedup key for an update.
     *
     * @param array $data Payload — must contain 'peer_id'+'message_id',
     *                    'pts', or 'update_id' for a specific key.
     */
    public static function computeDedupKey(int $accountId, string $type, array $data): string
    {
        $isMessage = \str_starts_with($type, 'update') && isset($data['peer_id'], $data['message_id']);
        if ($isMessage) {
            return 'msg:' . $data['peer_id'] . ':' . $data['message_id'];
        }
        if (isset($data['pts'])) {
            return 'pts:' . $accountId . ':' . $data['pts'];
        }
        if (isset($data['update_id'])) {
            return 'update:' . $accountId . ':' . $data['update_id'];
        }
        return 'update:' . $accountId . ':' . $type;
    }

    /**
     * Check whether a dedup key has been seen (Redis SETNX).
     *
     * Returns true if the key was already present (duplicate), false if
     * this call was the first to set it (not duplicate).
     *
     * @param int $ttlSeconds TTL for the Redis key (seconds).
     */
    public function isDuplicate(string $dedupKey, int $ttlSeconds = self::DEDUP_DEFAULT_MESSAGE_TTL): bool
    {
        $now = \time();
        if (isset($this->seenKeys[$dedupKey]) && $this->seenKeys[$dedupKey] > $now) {
            return true;
        }
        unset($this->seenKeys[$dedupKey]);

        $redisKey = self::DEDUP_PREFIX . $dedupKey;
        $set = $this->publisherConn->set(
            $redisKey,
            '1',
            (new SetOptions())->withTtl($ttlSeconds)->withoutOverwrite(),
        );

        $this->seenKeys[$dedupKey] = $now + $ttlSeconds;

        return !$set;
    }

    /**
     * Explicitly mark a dedup key as seen in Redis.
     */
    public function setSeen(string $dedupKey, int $ttlSeconds = self::DEDUP_DEFAULT_MESSAGE_TTL): void
    {
        $this->publisherConn->set(
            self::DEDUP_PREFIX . $dedupKey,
            '1',
            (new SetOptions())->withTtl($ttlSeconds),
        );
        $this->seenKeys[$dedupKey] = \time() + $ttlSeconds;
    }

    /**
     * Publish an update from one account into the bus.
     *
     * The update is deduped across accounts: if the computed dedup key
     * has already been seen, the publish is skipped.
     *
     * @param int    $accountId Originating account.
     * @param string $type      Update type (e.g. "updateNewMessage").
     * @param array  $data      Arbitrary payload.
     */
    public function emit(int $accountId, string $type, array $data): void
    {
        $dedupKey = self::computeDedupKey($accountId, $type, $data);

        $isMessage = \str_starts_with($type, 'update') && isset($data['peer_id'], $data['message_id']);
        $ttl = $isMessage
            ? ($this->deduplicationTtl['messages'] ?? self::DEDUP_DEFAULT_MESSAGE_TTL)
            : ($this->deduplicationTtl['service'] ?? self::DEDUP_DEFAULT_SERVICE_TTL);

        if ($this->isDuplicate($dedupKey, $ttl)) {
            return;
        }

        $this->publisherConn->publish(
            self::CHANNEL_UPDATES,
            \json_encode([
                'account_id' => $accountId,
                'type' => $type,
                'data' => $data,
                'dedup_key' => $dedupKey,
            ], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Register a listener for an update type.
     *
     * @param string     $type    Update type to listen for (e.g. "updateNewMessage").
     * @param callable   $handler Receives (int $accountId, string $type, array $data).
     * @param array      $filter  Optional key/value pairs the update data must match.
     */
    public function on(string $type, callable $handler, array $filter = []): void
    {
        $this->listeners[$type][] = [$handler, $filter];
    }

    /**
     * Start the dispatcher loop — subscribes to the updates channel and
     * dispatches to registered listeners.
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;

        $this->subscription = $this->subscriberConn->subscribe(self::CHANNEL_UPDATES);
        $subscription = $this->subscription;

        $listeners = &$this->listeners;

        EventLoop::queue(function () use ($listeners, $subscription): void {
            try {
                foreach ($subscription as $payload) {
                    if (!$this->running) {
                        break;
                    }

                    $message = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
                    $type = $message['type'] ?? '';
                    $accountId = $message['account_id'] ?? 0;
                    $data = $message['data'] ?? [];

                    if (!isset($listeners[$type])) {
                        continue;
                    }

                    foreach ($listeners[$type] as [$handler, $filter]) {
                        if ($filter !== [] && !empty(\array_diff_assoc($filter, $data))) {
                            continue;
                        }
                        $handler($accountId, $type, $data);
                    }
                }
            } catch (\Amp\Pipeline\DisposedException) {
                // Subscription was disposed by stop() — expected shutdown path.
            } finally {
                $this->running = false;
                $this->subscription = null;
            }
        });
    }

    /**
     * Stop the dispatcher — unsubscribes and marks the bus as stopped.
     */
    public function stop(): void
    {
        $this->running = false;

        if ($this->subscription !== null) {
            $this->subscription->unsubscribe();
            $this->subscription = null;
        }
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    private function createClient(string $dsn): RedisClient
    {
        try {
            return createRedisClient(RedisConfig::fromUri($dsn));
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to connect to Redis: ' . $e->getMessage(), 0, $e);
        }
    }
}
