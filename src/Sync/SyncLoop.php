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

namespace danog\MadelineProto\Sync;

use danog\Loop\PeriodicLoop;
use danog\MadelineProto\Accounts\AccountManager;
use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\RelationalStore;

/**
 * Injectable sync source.
 *
 * The real daemon supplies an MTProto-backed provider; tests supply a fake.
 * Implementations must return the raw Telegram data for one logged-in account
 * without performing any network access themselves.
 */
interface AccountDataProvider
{
    /**
     * Returns the Telegram data for one logged-in account.
     *
     * @return array{user?: array, messages?: array<int, array>, peers?: array<int, array>, chats?: array<int, array>, channels?: array<int, array>}
     */
    public function pull(int $accountId): array;
}

/**
 * Background sync loop: keeps the relational store current and proves the
 * **single source of truth** invariant — one entity row, many account links.
 *
 * Because every write goes through idempotent, Telegram-id-keyed upserts, a
 * user known to two accounts becomes ONE `users` row with TWO `account_entities`
 * links. After each write pass the affected cache keys are invalidated so the
 * Redis front stays coherent with the store.
 */
final class SyncLoop
{
    private ?PeriodicLoop $periodicLoop = null;

    /**
     * @param AccountManager     $accounts  Enumerates logged-in accounts.
     * @param RelationalStore    $store     Single source of truth (write once).
     * @param Cache              $cache     Redis front to invalidate after writes.
     * @param AccountDataProvider $provider Injectable sync source (MTProto or fake).
     * @param int                $intervalSeconds Period between sync passes.
     */
    public function __construct(
        private AccountManager $accounts,
        private RelationalStore $store,
        private Cache $cache,
        private AccountDataProvider $provider,
        private int $intervalSeconds = 30
    ) {
    }

    /**
     * Run one sync pass over every account. Callable manually in tests.
     */
    public function tick(): void
    {
        $keys = [];

        foreach ($this->accounts->listAccounts() as $account) {
            $accountId = (int) $account['id'];
            $data = $this->provider->pull($accountId);

            $user = $data['user'] ?? null;
            if (is_array($user) && isset($user['user_id'])) {
                $userId = (int) $user['user_id'];
                $this->store->upsertUser($user);
                // Single source of truth: every account that "is" this user links
                // the SAME users row rather than creating its own copy.
                $this->store->linkAccountEntity($accountId, $userId, 'self');
                $keys[] = Cache::userKey($userId);
            }

            foreach ($data['chats'] ?? [] as $chat) {
                if (!is_array($chat) || !isset($chat['id'])) {
                    continue;
                }
                $chatId = (int) $chat['id'];
                $this->store->upsertChat($chat);
                $this->store->linkAccountEntity($accountId, $chatId, 'chat');
                $keys[] = Cache::chatKey($chatId);
                if (isset($chat['username'])) {
                    $keys[] = Cache::peerKey((string) $chat['username']);
                }
            }

            foreach ($data['channels'] ?? [] as $channel) {
                if (!is_array($channel) || !isset($channel['id'])) {
                    continue;
                }
                $channelId = (int) $channel['id'];
                $this->store->upsertChannel($channel);
                $this->store->linkAccountEntity($accountId, $channelId, 'channel');
                $keys[] = Cache::channelKey($channelId);
                if (isset($channel['username'])) {
                    $keys[] = Cache::peerKey((string) $channel['username']);
                }
            }

            foreach ($data['peers'] ?? [] as $peer) {
                if (!is_array($peer) || !isset($peer['peer_id'])) {
                    continue;
                }
                $peerId = (int) $peer['peer_id'];
                $type = (string) ($peer['type'] ?? 'user');
                $username = $peer['username'] ?? null;
                $phone = $peer['phone'] ?? null;
                $this->store->indexPeer($peerId, $type, $username, $phone);
                $this->store->linkAccountEntity($accountId, $peerId, $type);
                if ($username !== null) {
                    $keys[] = Cache::peerKey((string) $username);
                }
                if ($phone !== null) {
                    $keys[] = Cache::peerKey((string) $phone);
                }
            }

            foreach ($data['messages'] ?? [] as $msg) {
                if (!is_array($msg) || !isset($msg['peer_id'], $msg['id'])) {
                    continue;
                }
                $peerId = (int) $msg['peer_id'];
                $msgId = (int) $msg['id'];
                $this->store->upsertMessage($msg);
                $keys[] = Cache::messageKey($peerId, $msgId);
            }
        }

        if ($keys !== []) {
            // Cache invalidation must be exact: drop the precise keys that the
            // pass may have made stale so the front never serves stale data.
            $this->cache->delete(...$keys)->await();
        }
    }

    /**
     * Start the background loop (wraps a PeriodicLoop on $intervalSeconds).
     */
    public function start(): void
    {
        if ($this->periodicLoop !== null) {
            return;
        }

        $this->periodicLoop = new PeriodicLoop(
            function (PeriodicLoop $loop): bool {
                $this->tick();

                return false;
            },
            'sync-loop',
            (float) $this->intervalSeconds
        );
        $this->periodicLoop->start();
    }

    /**
     * Stop the background loop.
     */
    public function stop(): void
    {
        if ($this->periodicLoop !== null) {
            $this->periodicLoop->stop();
            $this->periodicLoop = null;
        }
    }
}
