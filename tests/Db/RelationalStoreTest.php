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

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

/**
 * RelationalStore acceptance tests (SQLite, no external services).
 */
class RelationalStoreTest extends TestCase
{
    private PdoDriver $driver;
    private RelationalStore $store;

    protected function setUp(): void
    {
        $this->driver = new PdoDriver('sqlite::memory:');
        (new Migrations($this->driver))->migrate();
        $this->store = new RelationalStore($this->driver);
    }

    public function testUpsertUserPreservesRawAndRoundTripsId(): void
    {
        $raw = json_encode([
            'user_id' => 100123456789,
            'access_hash' => '5123456789012345678',
            'username' => 'alice_wonder',
            'phone' => '+10000000001',
            'first_name' => 'Alice',
            'bytes' => base64_encode("\x00\x01\x02\xff"),
        ], JSON_THROW_ON_ERROR);

        $user = [
            'user_id' => 100123456789,
            'access_hash' => '5123456789012345678',
            'username' => 'alice_wonder',
            'phone' => '+10000000001',
            'first_name' => 'Alice',
            'raw' => $raw,
        ];

        $this->store->upsertUser($user);

        $got = $this->store->getUser(100123456789);
        $this->assertNotNull($got);
        $this->assertSame(100123456789, (int) $got['user_id']);
        $this->assertSame($raw, $got['raw']);
    }

    public function testIdempotentUserUpsertYieldsSingleRow(): void
    {
        $user = [
            'user_id' => 200123456789,
            'username' => 'bob',
            'raw' => json_encode(['user_id' => 200123456789, 'username' => 'bob']),
        ];

        $this->store->upsertUser($user);
        $this->store->upsertUser($user);
        // And again with an updated first_name (still same id).
        $this->store->upsertUser($user + ['first_name' => 'Bobby']);

        $rows = $this->driver->query('SELECT user_id FROM users');
        $this->assertCount(1, $rows);
        $this->assertSame(200123456789, (int) $rows[0]['user_id']);
    }

    public function testTwoAccountsLinkSameUserSingleRow(): void
    {
        $userId = 300123456789;
        $raw = json_encode(['user_id' => $userId, 'username' => 'shared']);

        $this->store->upsertAccount(1, 111, 'hash1', 'authorized');
        $this->store->upsertAccount(2, 222, 'hash2', 'authorized');

        $this->store->upsertUser(['user_id' => $userId, 'username' => 'shared', 'raw' => $raw]);
        // Both accounts link to the SAME user.
        $this->store->linkAccountEntity(1, $userId, 'contact');
        $this->store->linkAccountEntity(2, $userId, 'contact');

        // Both accounts link to the SAME user; each account resolves to it.
        $this->assertCount(1, $this->store->getAccountEntities(1));
        $this->assertCount(1, $this->store->getAccountEntities(2));

        $userRows = $this->driver->query('SELECT user_id FROM users');
        $this->assertCount(1, $userRows);

        // Every account that linked resolves to the same user row.
        $this->assertSame($userId, (int) $this->store->getAccountEntities(1)[0]['entity_id']);
        $this->assertSame($userId, (int) $this->store->getAccountEntities(2)[0]['entity_id']);
    }

    public function testGetMessagesBySenderIsCrossAccount(): void
    {
        $fromId = 400123456789;

        // Two distinct accounts' chats (different peer_id) contain messages
        // authored by the same sender.
        $this->store->upsertMessage([
            'peer_id' => 1,
            'id' => 10,
            'from_id' => $fromId,
            'date' => 1000,
            'raw' => json_encode(['peer_id' => 1, 'id' => 10]),
        ]);
        $this->store->upsertMessage([
            'peer_id' => 2,
            'id' => 20,
            'from_id' => $fromId,
            'date' => 2000,
            'raw' => json_encode(['peer_id' => 2, 'id' => 20]),
        ]);
        // A message from a different sender must NOT be included.
        $this->store->upsertMessage([
            'peer_id' => 2,
            'id' => 21,
            'from_id' => 999999,
            'date' => 3000,
            'raw' => json_encode(['peer_id' => 2, 'id' => 21]),
        ]);

        $messages = $this->store->getMessagesBySender($fromId);
        $this->assertCount(2, $messages);

        $peerIds = array_map(fn ($m) => (int) $m['peer_id'], $messages);
        sort($peerIds);
        $this->assertSame([1, 2], $peerIds);
    }

    public function testFileReferenceRoundTripsRawBytes(): void
    {
        $reference = random_bytes(64) . "\x00\x01\x02\xff\xfe";

        $this->store->upsertFile(111, 222, $reference, 'photo');

        $got = $this->store->getFile(111, 222);
        $this->assertNotNull($got);
        $this->assertIsString($got['file_reference']);
        $this->assertSame($reference, $got['file_reference']);
    }

    public function testResolvePeerByUsernameAndPhone(): void
    {
        $this->store->upsertUser([
            'user_id' => 500123456789,
            'username' => 'resolvable',
            'phone' => '+19999999999',
            'raw' => json_encode(['user_id' => 500123456789]),
        ]);

        $byUsername = $this->store->resolvePeer('resolvable');
        $this->assertNotNull($byUsername);
        $this->assertSame(500123456789, (int) $byUsername['peer_id']);

        $byPhone = $this->store->resolvePeer('+19999999999');
        $this->assertNotNull($byPhone);
        $this->assertSame(500123456789, (int) $byPhone['peer_id']);

        $missing = $this->store->resolvePeer('nobody');
        $this->assertNull($missing);
    }
}
