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

use danog\MadelineProto\Accounts\AccountManager;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * AccountManager acceptance tests (SQLite, fake auth performer, no network).
 */
class AccountManagerTest extends TestCase
{
    private PdoDriver $driver;
    private RelationalStore $store;
    private AccountManager $manager;

    /** @var array<int, array{user_id:int, session_blob:string, auth_state:string}> */
    private array $authCalls = [];

    protected function setUp(): void
    {
        $this->driver = new PdoDriver('sqlite::memory:');
        (new Migrations($this->driver))->migrate();
        $this->store = new RelationalStore($this->driver);
        $this->authCalls = [];

        // Fake auth performer: records the call and returns a deterministic result.
        $this->manager = new AccountManager(
            $this->store,
            function (int $apiId, string $apiHash, ?string $sessionBlob): array {
                $this->authCalls[] = [
                    'api_id' => $apiId,
                    'api_hash' => $apiHash,
                    'session_blob' => $sessionBlob,
                ];

                // On relogin the performer receives the stored blob and confirms the same id.
                $userId = $sessionBlob === 'stored-blob' ? 777 : 777;
                $newBlob = $sessionBlob === null ? 'fresh-blob' : 'reattached-blob';

                return [
                    'user_id' => $userId,
                    'session_blob' => $newBlob,
                    'auth_state' => 'authorized',
                ];
            }
        );
    }

    public function testAddCredentialsAndHasCredentials(): void
    {
        $this->assertFalse($this->manager->hasCredentials());

        $this->manager->addApiCredentials(12345, 'deadbeefcafe');

        $this->assertTrue($this->manager->hasCredentials());
    }

    public function testRequireCredentialsThrowsWhenEmpty(): void
    {
        $this->expectException(Exception::class);
        $this->manager->requireCredentials();
    }

    public function testLoginPersistsAccountAndLinksSelf(): void
    {
        $this->manager->addApiCredentials(12345, 'deadbeefcafe');

        $userId = $this->manager->login(12345, 'deadbeefcafe');

        $this->assertSame(777, $userId);

        $account = $this->manager->getAccount(777);
        $this->assertNotNull($account);
        $this->assertSame(777, (int) $account['id']);
        $this->assertSame(12345, (int) $account['api_id']);
        $this->assertSame('authorized', $account['auth_state']);
        $this->assertSame('fresh-blob', $account['session_blob']);

        // Single source of truth: the account links its own user row.
        $entities = $this->store->getAccountEntities(777);
        $this->assertCount(1, $entities);
        $this->assertSame(777, (int) $entities[0]['entity_id']);
        $this->assertSame('self', $entities[0]['relationship']);

        // Pending credential placeholder must be gone (no orphan row).
        $this->assertNull($this->manager->getAccount(-12345));

        // The login drove the auth performer with no prior blob.
        $this->assertCount(1, $this->authCalls);
        $this->assertNull($this->authCalls[0]['session_blob']);
    }

    public function testLoginWithoutCredentialsThrows(): void
    {
        $this->expectException(Exception::class);
        $this->manager->login(12345, 'deadbeefcafe');
    }

    public function testReloginReattachesUsingStoredBlob(): void
    {
        $this->manager->addApiCredentials(12345, 'deadbeefcafe');
        $this->manager->login(12345, 'deadbeefcafe');

        // Prepare a stored blob as the performer would have left it.
        $this->store->upsertAccount(777, 12345, 'deadbeefcafe', 'authorized', 'stored-blob');

        $userId = $this->manager->relogin(777);

        $this->assertSame(777, $userId);

        // The performer must have received the stored blob.
        $this->assertCount(2, $this->authCalls);
        $this->assertSame('stored-blob', $this->authCalls[1]['session_blob']);

        $account = $this->manager->getAccount(777);
        $this->assertSame('reattached-blob', $account['session_blob']);
        $this->assertSame('authorized', $account['auth_state']);
    }

    public function testLogoutClearsAuthStateAndBlobButKeepsAccount(): void
    {
        $this->manager->addApiCredentials(12345, 'deadbeefcafe');
        $this->manager->login(12345, 'deadbeefcafe');

        $this->manager->logout(777);

        $account = $this->manager->getAccount(777);
        $this->assertNotNull($account);
        $this->assertNull($account['auth_state']);
        $this->assertNull($account['session_blob']);
        // The credential persists so the account can log in again.
        $this->assertSame(12345, (int) $account['api_id']);

        // Still listed.
        $listed = $this->manager->listAccounts();
        $this->assertCount(1, $listed);
        $this->assertSame(777, (int) $listed[0]['id']);
        // session_blob is stripped from listAccounts output.
        $this->assertArrayNotHasKey('session_blob', $listed[0]);

        // And can be removed entirely.
        $this->manager->removeAccount(777);
        $this->assertNull($this->manager->getAccount(777));
        $this->assertCount(0, $this->manager->listAccounts());
    }
}
