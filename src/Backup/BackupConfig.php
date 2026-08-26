<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTIC PARTICULAR PURPOSE.
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

use InvalidArgumentException;

final class BackupSetConfig
{
    /** @var list<string> */
    private array $paths;
    /** @var list<string> */
    private array $exclude;
    private ?string $preCommand;
    private ?int $chunkSize;

    /**
     * @param array{paths?: list<string>, exclude?: list<string>, pre_command?: ?string, chunk_size?: ?int}|list<string> $definition
     */
    public function __construct(array $definition)
    {
        if (array_is_list($definition)) {
            $this->paths = $definition;
            $this->exclude = [];
            $this->preCommand = null;
            $this->chunkSize = null;
        } else {
            if (!isset($definition['paths']) || !is_array($definition['paths']) || $definition['paths'] === []) {
                throw new InvalidArgumentException('paths required');
            }
            $this->paths = $definition['paths'];
            $this->exclude = $definition['exclude'] ?? [];
            $this->preCommand = $definition['pre_command'] ?? null;
            $this->chunkSize = $definition['chunk_size'] ?? null;

            foreach (['paths', 'exclude', 'pre_command', 'chunk_size'] as $known) {
                unset($definition[$known]);
            }
            if ($definition !== []) {
                throw new InvalidArgumentException('unknown set key: ' . key($definition));
            }
        }

        foreach ($this->paths as $p) {
            if (!is_string($p) || $p === '' || $p[0] !== '/') {
                throw new InvalidArgumentException('paths must be absolute: ' . (string) $p);
            }
        }
        if ($this->exclude !== [] && (!is_array($this->exclude) || array_is_list($this->exclude) === false)) {
            throw new InvalidArgumentException('exclude must be a list of glob strings');
        }
        if ($this->preCommand !== null && !is_string($this->preCommand)) {
            throw new InvalidArgumentException('pre_command must be a string');
        }
        if ($this->chunkSize !== null && (!is_int($this->chunkSize) || $this->chunkSize <= 0)) {
            throw new InvalidArgumentException('chunk_size must be an int > 0');
        }
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return list<string> */
    public function exclude(): array
    {
        return $this->exclude;
    }

    public function preCommand(): ?string
    {
        return $this->preCommand;
    }

    public function chunkSize(): ?int
    {
        return $this->chunkSize;
    }

    /**
     * Returns true when $relativePath is NOT excluded (i.e. should be kept).
     */
    public function matchesExclude(string $relativePath): bool
    {
        foreach ($this->exclude as $pattern) {
            if (fnmatch($pattern, $relativePath, FNM_NOESCAPE)) {
                return false;
            }
        }
        return true;
    }
}

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
