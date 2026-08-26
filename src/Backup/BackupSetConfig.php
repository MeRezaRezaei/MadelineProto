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

final class BackupSetConfig
{
    /** @var list<string> */
    private array $paths;
    /** @var list<string> */
    private array $exclude;
    private ?string $preCommand;
    private ?int $chunkSize;

    /**
     * @param array{paths?: list<string>, exclude?: list<string>, pre_command?: ?string, chunk_size?: ?int}|list<string> $pathsOrDefinition
     */
    public function __construct(
        array $pathsOrDefinition,
        array $exclude = [],
        ?string $preCommand = null,
        ?int $chunkSize = null
    ) {
        if (isset($pathsOrDefinition['paths'])) {
            $def = $pathsOrDefinition;
            $this->paths = $def['paths'];
            $this->exclude = $def['exclude'] ?? [];
            $this->preCommand = $def['pre_command'] ?? null;
            $this->chunkSize = $def['chunk_size'] ?? null;

            foreach (['paths', 'exclude', 'pre_command', 'chunk_size'] as $known) {
                unset($def[$known]);
            }
            if ($def !== []) {
                throw new InvalidArgumentException('unknown set key: ' . key($def));
            }
        } elseif (array_is_list($pathsOrDefinition)) {
            $this->paths = $pathsOrDefinition;
            $this->exclude = $exclude;
            $this->preCommand = $preCommand;
            $this->chunkSize = $chunkSize;
        } else {
            throw new InvalidArgumentException('paths required');
        }

        if ($this->paths === []) {
            throw new InvalidArgumentException('paths required');
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
