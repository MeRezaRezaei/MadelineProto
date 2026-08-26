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

use RuntimeException;

final class Chunker
{
    /**
     * Split $path into <=$chunkSize temp files inside $tmpDir.
     * Streams with fopen/fread (never file_get_contents — sources can be ~2 GB).
     * @return list<array{tmpPath: string, size: int}>
     */
    public static function split(string $path, int $chunkSize, string $tmpDir): array
    {
        if (!is_dir($tmpDir)) {
            throw new RuntimeException('tmpDir is not a directory: ' . $tmpDir);
        }

        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot open source file: ' . $path);
        }

        $chunks = [];
        $i = 0;
        while (($buf = fread($in, $chunkSize)) !== '' && $buf !== false) {
            $tmpPath = $tmpDir . '/chunk-' . $i . '-' . uniqid() . '.part';
            $out = fopen($tmpPath, 'wb');
            fwrite($out, $buf);
            fclose($out);

            $chunks[] = ['tmpPath' => $tmpPath, 'size' => strlen($buf)];
            $i++;
        }

        fclose($in);

        return $chunks;
    }
}
