<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Sync;

use PHPUnit\Framework\TestCase;

final class TgUpdateForwarderTest extends TestCase
{
    private RecordingProcessor $sink;

    protected function setUp(): void
    {
        $this->sink = new RecordingProcessor();
        TgUpdateForwarder::configure($this->sink, 42);
    }

    private function forwarder(): TgUpdateForwarder
    {
        /** @var TgUpdateForwarder $f */
        $f = (new \ReflectionClass(TgUpdateForwarder::class))->newInstanceWithoutConstructor();
        return $f;
    }

    public function testForwardsNewMessageWithNormalizedPeer(): void
    {
        $this->forwarder()->onAny([
            '_' => 'updateNewMessage',
            'message' => [
                '_' => 'message',
                'id' => 7,
                'peer_id' => ['_' => 'peerUser', 'user_id' => 99],
                'date' => 1700000000,
                'message' => 'hi',
            ],
        ]);

        $this->assertSame([42, 'updateNewMessage', [
            '_' => 'message',
            'id' => 7,
            'peer_id' => 99,
            'date' => 1700000000,
            'message' => 'hi',
        ]], $this->sink->calls[0]);
    }

    public function testForwardsEditChannelMessageMappedToEdit(): void
    {
        $this->forwarder()->onAny([
            '_' => 'updateEditChannelMessage',
            'message' => [
                '_' => 'message',
                'id' => 11,
                'peer_id' => ['_' => 'peerChannel', 'channel_id' => -100123],
            ],
        ]);

        [$accountId, $type, $data] = $this->sink->calls[0];
        $this->assertSame(42, $accountId);
        $this->assertSame('updateEditMessage', $type);
        $this->assertSame(-100123, $data['peer_id']);
    }

    public function testForwardsDeleteMessagesNormalizingIds(): void
    {
        $this->forwarder()->onAny([
            '_' => 'updateDeleteMessages',
            'peer_id' => ['_' => 'peerChat', 'chat_id' => 555],
            'messages' => [3, 4, 5],
        ]);

        [$accountId, $type, $data] = $this->sink->calls[0];
        $this->assertSame('updateDeleteMessages', $type);
        $this->assertSame(555, $data['peer_id']);
        $this->assertSame([3, 4, 5], $data['ids']);
    }

    public function testIgnoresUnhandledUpdateTypes(): void
    {
        $this->forwarder()->onAny(['_' => 'updateUserStatus', 'status' => []]);
        $this->assertSame([], $this->sink->calls);
    }
}

final class RecordingProcessor implements UpdateProcessor
{
    /** @var array<int, array{int, string, array}> */
    public array $calls = [];

    public function process(int $accountId, string $type, array $data): void
    {
        $this->calls[] = [$accountId, $type, $data];
    }
}
