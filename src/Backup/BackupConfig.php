<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    The MadelineProto Team
 * @copyright 2016-2025 The MadelineProto Team
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Backup;

use InvalidArgumentException;

final class BackupConfig
{
    /** @var array<string, BackupSetConfig> */
    private array $sets;

    /** @param array<string, BackupSetConfig> $sets */
    public function __construct(array $sets)
    {
        $this->sets = $sets;
    }

    public static function loadFile(string $path): self
    {
        $raw = file_get_contents($path);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('invalid config: expected object');
        }
        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['sets']) || !is_array($data['sets']) || $data['sets'] === []) {
            throw new InvalidArgumentException('sets object required');
        }
        $sets = [];
        foreach ($data['sets'] as $name => $definition) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('set name required');
            }
            if (!is_array($definition)) {
                throw new InvalidArgumentException('set definition must be an object for: ' . $name);
            }
            $sets[$name] = new BackupSetConfig($definition);
        }
        return new self($sets);
    }

    public function has(string $name): bool
    {
        return isset($this->sets[$name]);
    }

    public function set(string $name): BackupSetConfig
    {
        if (!isset($this->sets[$name])) {
            throw new InvalidArgumentException('unknown set: ' . $name);
        }
        return $this->sets[$name];
    }

    /** @return array<string, BackupSetConfig> */
    public function sets(): array
    {
        return $this->sets;
    }
}
