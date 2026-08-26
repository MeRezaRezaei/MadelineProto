<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    The MadelineProto Team
 * @copyright 2016-2025 The MadelineProto Team
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Backup;

/**
 * In-memory fake of VaultInterface for tests.
 *
 * Channel ids are stable, incrementing negative numbers per unique set id
 * (first allocation is -1000000000001, matching the live-feed test contract).
 * Chunks and manifests are kept in a nested map keyed by (channelId, msgId)
 * with msg_id incrementing from 1 per channel.
 */
final class InMemoryVault implements VaultInterface
{
    private int $seq = -1000000000000;
    /** @var array<string, int> */
    private array $channels = [];
    /** @var array<int, string> */
    private array $titles = [];
    /** @var array<int, int> */
    private array $counters = [];
    /** @var array<int, array<int, array{0: string, 1: string}>> */
    private array $data = [];

    public function __construct(private ?string $channelTitlePrefix = null)
    {
    }

    public function ensureChannel(string $setId): int
    {
        if (isset($this->channels[$setId])) {
            return $this->channels[$setId];
        }
        $id = --$this->seq;
        $this->channels[$setId] = $id;
        if ($this->channelTitlePrefix !== null) {
            $this->titles[$id] = $this->channelTitlePrefix . $setId;
        }
        $this->counters[$id] = 1;
        $this->data[$id] = [];

        return $id;
    }

    public function uploadChunk(int $channelId, string $name, string $tmpPath): array
    {
        $msgId = $this->nextMsg($channelId);
        $this->data[$channelId][$msgId] = [$name, file_get_contents($tmpPath)];

        return [$msgId, 'fake:' . $name];
    }

    public function uploadManifest(int $channelId, string $snapshotId, string $json): int
    {
        $msgId = $this->nextMsg($channelId);
        $this->data[$channelId][$msgId] = ['manifest-' . $snapshotId . '.json', $json];

        return $msgId;
    }

    public function downloadChunk(int $channelId, int $msgId, string $destPath): void
    {
        file_put_contents($destPath, $this->data[$channelId][$msgId][1]);
    }

    public function downloadManifest(int $channelId, int $msgId): string
    {
        return $this->data[$channelId][$msgId][1];
    }

    /** @return array<int, array<int, string>> channelId => msgId => content (non-manifest) */
    public function chunks(): array
    {
        return $this->filter(static fn (string $name): bool => !str_starts_with($name, 'manifest-'));
    }

    /** @return array<int, array<int, string>> channelId => msgId => content (manifests only) */
    public function manifests(): array
    {
        return $this->filter(static fn (string $name): bool => str_starts_with($name, 'manifest-'));
    }

    private function nextMsg(int $channelId): int
    {
        return $this->counters[$channelId]++;
    }

    /**
     * @param callable(string): bool $keep
     * @return array<int, array<int, string>>
     */
    private function filter(callable $keep): array
    {
        $out = [];
        foreach ($this->data as $channelId => $entries) {
            foreach ($entries as $msgId => [$name, $content]) {
                if ($keep($name)) {
                    $out[$channelId][$msgId] = $content;
                }
            }
        }

        return $out;
    }
}
