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

namespace danog\MadelineProto\Accounts;

use danog\MadelineProto\Db\RelationalStore;
use Exception;

/**
 * Owns the lifecycle of Telegram sessions and enforces the
 * "≥1 api credential before login" invariant.
 *
 * The manager performs no real Telegram network auth itself. Instead it drives
 * an injectable `authPerformer` callable, so it can be unit-tested without any
 * network access.
 *
 * A registered (pending) credential is stored as an `accounts` row keyed by the
 * reserved negative id `-apiId` (Telegram user_ids are always positive, so this
 * never collides). On the first successful login the real account row is created
 * under the Telegram user_id and the pending placeholder is removed, making the
 * `accounts` table the single source of truth that a logged-in account links its
 * own user row via `account_entities`.
 */
class AccountManager
{
    /**
     * Reserved id space for pending (not-yet-logged-in) credentials.
     */
    private const PENDING_ID_PREFIX = '-';

    private ?\Closure $authPerformer = null;

    /**
     * @param RelationalStore             $store        Backing relational store.
     * @param null|callable(int,string,?string):array $authPerformer Injectable auth performer returning
     *                                                  ['user_id' => int, 'session_blob' => string, 'auth_state' => string].
     */
    public function __construct(
        private RelationalStore $store,
        ?callable $authPerformer = null
    ) {
        $this->authPerformer = $authPerformer === null ? null : \Closure::fromCallable($authPerformer);
    }

    /**
     * Persist a (pending) api credential. The account id is filled on the first
     * successful login.
     */
    public function addApiCredentials(int $apiId, string $apiHash): void
    {
        $this->store->upsertAccount($this->pendingId($apiId), $apiId, $apiHash, null, null);
    }

    /**
     * Whether at least one api credential is registered.
     */
    public function hasCredentials(): bool
    {
        foreach ($this->store->listAccounts() as $row) {
            if ($row['api_id'] !== null && $row['api_hash'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enforce the "≥1 api credential before login" invariant.
     *
     * @throws Exception When no credential is registered.
     */
    public function requireCredentials(): void
    {
        if (!$this->hasCredentials()) {
            throw new Exception('Cannot login: no api credentials have been registered.');
        }
    }

    /**
     * Log an account in: drives the auth performer, persists the resulting user
     * id + session blob + auth state, links the account to its own user row
     * (single source of truth), and removes the pending credential placeholder.
     *
     * @throws Exception When no credential exists for the supplied api_id, or no auth performer is configured.
     */
    public function login(int $apiId, string $apiHash, ?string $sessionBlob = null): int
    {
        $this->requireCredentials();

        if ($this->store->getAccount($this->pendingId($apiId)) === null) {
            throw new Exception('Cannot login: no registered credential for api_id ' . $apiId . '.');
        }

        $result = $this->performAuth($apiId, $apiHash, $sessionBlob);
        $userId = (int) $result['user_id'];

        $this->store->upsertAccount(
            $userId,
            $apiId,
            $apiHash,
            $result['auth_state'] ?? null,
            $result['session_blob'] ?? null
        );
        $this->store->linkAccountEntity($userId, $userId, 'self');

        // The pending credential placeholder is now consumed by the real account.
        $this->store->deleteAccount($this->pendingId($apiId));

        return $userId;
    }

    /**
     * Re-attach a previously logged-in account using its stored session_blob,
     * without re-authenticating (no user interaction).
     *
     * @throws Exception When the account is unknown or has no stored session.
     */
    public function relogin(int $accountId): int
    {
        $account = $this->store->getAccount($accountId);
        if ($account === null) {
            throw new Exception('Cannot relogin: unknown account ' . $accountId . '.');
        }
        if (($apiId = $account['api_id']) === null || ($apiHash = $account['api_hash']) === null) {
            throw new Exception('Cannot relogin: account ' . $accountId . ' has no api credentials.');
        }

        $result = $this->performAuth((int) $apiId, (string) $apiHash, $account['session_blob'] ?? null);
        $userId = (int) $result['user_id'];

        $this->store->upsertAccount(
            $userId,
            (int) $apiId,
            (string) $apiHash,
            $result['auth_state'] ?? null,
            $result['session_blob'] ?? null
        );
        $this->store->linkAccountEntity($userId, $userId, 'self');

        return $userId;
    }

    /**
     * Clear the auth state + session blob. The credential row is preserved so the
     * account can log in again.
     */
    public function logout(int $accountId): void
    {
        $account = $this->store->getAccount($accountId);
        if ($account === null) {
            return;
        }

        $this->store->upsertAccount(
            $accountId,
            (int) $account['api_id'],
            (string) $account['api_hash'],
            null,
            null
        );
    }

    /**
     * Remove an account (credential + session + entity links).
     */
    public function removeAccount(int $accountId): void
    {
        $this->store->deleteAccount($accountId);
    }

    /**
     * List account rows, with the session_blob stripped.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(): array
    {
        $rows = $this->store->listAccounts();
        foreach ($rows as &$row) {
            unset($row['session_blob']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Import a pre-authenticated or migrated account directly into the store.
     */
    public function importAccount(
        int $userId,
        int $apiId,
        string $apiHash,
        ?string $authState,
        ?string $sessionBlob = null
    ): void {
        $this->store->upsertAccount($userId, $apiId, $apiHash, $authState, $sessionBlob);
        if ($userId > 0) {
            $this->store->linkAccountEntity($userId, $userId, 'self');
        }
    }

    /**
     * Get a single account row (including session_blob).
     *
     * @return array<string, mixed>|null
     */
    public function getAccount(int $accountId): ?array
    {
        return $this->store->getAccount($accountId);
    }

    /**
     * @throws Exception When no auth performer is configured.
     */
    private function performAuth(int $apiId, string $apiHash, ?string $sessionBlob): array
    {
        if ($this->authPerformer === null) {
            throw new Exception('No auth performer configured; cannot authenticate.');
        }

        $result = ($this->authPerformer)($apiId, $apiHash, $sessionBlob);

        if (!is_array($result) || !isset($result['user_id'])) {
            throw new Exception('authPerformer returned an invalid result.');
        }

        return $result;
    }

    private function pendingId(int $apiId): int
    {
        return (int) (self::PENDING_ID_PREFIX . $apiId);
    }
}
