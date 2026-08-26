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
 * Two-connection hot-reload design:
 * - Connection A (subscriber-only): subscribes to madeline:updates, never
 *   used for anything else so subscriptions are never dropped by control traffic.
 * - Connection B (control publisher): RedisClient used to publish register/
 *   unregister/reload commands on madeline:control. Never subscribes.
 *
 * Design for testability: inject RedisClient / RedisConnector instances
 * (or DSN strings) so no real network is needed in unit tests.
 */
class EventBus
{
    private const CHANNEL_UPDATES = 'madeline:updates';
    private const CHANNEL_CONTROL = 'madeline:control';
    private const DEDUP_PREFIX = 'madeline:dedup:';
    private const DEDUP_DEFAULT_MESSAGE_TTL = 3600;
    private const DEDUP_DEFAULT_SERVICE_TTL = 300;

    /** @var array<string, list<array{callable, array}>> */
    private array $listeners = [];

    /** @var array<string, array{string, callable, array}> Handler registry keyed by id: [type, callable, filter]. */
    private array $handlerRegistry = [];

    /** @var array<string, int> In-memory seen-set for fast local dedup (value = expiry timestamp). */
    private array $seenKeys = [];

    private ?RedisSubscription $subscription = null;
    private bool $running = false;
    private int $connectionAReconnects = 0;

    private readonly array $deduplicationTtl;
    private readonly RedisClient $publisherConn;
    private readonly RedisClient $controlConn;
    private readonly RedisSubscriber $subscriberConn;

    /**
     * @param RedisClient|string    $publisher          Connection for publishing updates (accounts emit here).
     * @param RedisConnector|string $subscriber         Connector or DSN for Connection A (updates subscriber).
     * @param array<string,int>|RedisClient|string $deduplicationTtlOrControl  TTL per update type (seconds) OR control connection for backward compatibility.
     * @param RedisClient|string    $control            Connection for publishing control commands (Connection B).
     */
    public function __construct(
        RedisClient|string $publisher,
        RedisConnector|string $subscriber,
        array|RedisClient|string $deduplicationTtlOrControl = ['messages' => 3600, 'service' => 300],
        RedisClient|string $control = '',
    ) {
        $this->publisherConn = \is_string($publisher)
            ? $this->createClient($publisher)
            : $publisher;

        // Handle backward compatibility: 3rd arg could be dedupTtl array or control connection
        if (\is_array($deduplicationTtlOrControl)) {
            $this->deduplicationTtl = $deduplicationTtlOrControl;
            $controlConn = $control;
        } else {
            $this->deduplicationTtl = ['messages' => 3600, 'service' => 300];
            $controlConn = $deduplicationTtlOrControl;
        }

        $this->controlConn = (\is_string($controlConn) && $controlConn !== '')
            ? $this->createClient($controlConn)
            : ($controlConn instanceof RedisClient ? $controlConn : $this->publisherConn);

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
     * Register a handler via the control channel (Connection B).
     *
     * Stores the callable in the local handler registry and publishes
     * a register command on madeline:control so peer processes can
     * pick it up.
     *
     * @param string   $type    Update type to listen for.
     * @param string   $id      Stable handler identifier for later unregistration.
     * @param callable $handler Receives (int $accountId, string $type, array $data).
     * @param array    $filter  Optional key/value filter.
     *
     * @return string The handler id (same as $id).
     */
    public function controlRegister(string $type, string $id, callable $handler, array $filter = []): string
    {
        $this->handlerRegistry[$id] = [$type, $handler, $filter];
        $this->listeners[$type][] = [$handler, $filter];

        $this->controlConn->publish(
            self::CHANNEL_CONTROL,
            \json_encode([
                'action' => 'register',
                'type' => $type,
                'id' => $id,
            ], \JSON_THROW_ON_ERROR),
        );

        return $id;
    }

    /**
     * Unregister a handler by its id.
     *
     * Removes the handler from the in-memory registry AND the active
     * listeners list, then publishes an unregister command.
     */
    public function controlUnregister(string $id): void
    {
        if (!isset($this->handlerRegistry[$id])) {
            return;
        }

        [, $handler, $filter] = $this->handlerRegistry[$id];
        unset($this->handlerRegistry[$id]);

        foreach ($this->listeners as $type => $entries) {
            $this->listeners[$type] = \array_values(\array_filter(
                $entries,
                static fn (array $entry): bool => $entry[0] !== $handler || $entry[1] !== $filter,
            ));
            if ($this->listeners[$type] === []) {
                unset($this->listeners[$type]);
            }
        }

        $this->controlConn->publish(
            self::CHANNEL_CONTROL,
            \json_encode([
                'action' => 'unregister',
                'id' => $id,
            ], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Reload the handler registry without dropping Connection A.
     *
     * The updates subscription (Connection A) is never touched; only
     * the in-memory listeners array is rebuilt from the handler registry.
     */
    public function reload(): void
    {
        $newListeners = [];
        foreach ($this->handlerRegistry as [$type, $handler, $filter]) {
            $newListeners[$type][] = [$handler, $filter];
        }
        $this->listeners = $newListeners;

        // Publish reload command so peer processes can rebuild too.
        $this->controlConn->publish(
            self::CHANNEL_CONTROL,
            \json_encode([
                'action' => 'reload',
                'handler_ids' => \array_keys($this->handlerRegistry),
            ], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Number of times Connection A has been reconnected (should stay 0).
     */
    public function getConnectionAReconnects(): int
    {
        return $this->connectionAReconnects;
    }

    /**
     * Start the dispatcher loop — subscribes to the updates channel (Connection A).
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;

        // Connection A — updates subscriber (never reconnected during reload).
        $this->subscription = $this->subscriberConn->subscribe(self::CHANNEL_UPDATES);
        $subscription = $this->subscription;

        $listeners = &$this->listeners;

        EventLoop::queue(function () use (&$listeners, $subscription): void {
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