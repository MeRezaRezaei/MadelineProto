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

final class Crypto
{
    private const CHUNK = 65536;
    private const MAC = 17;

    /** 32 hex chars (16 random bytes). */
    public static function generateSalt(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Raw 32-byte key, Argon2id MODERATE. */
    public static function deriveKey(string $passphrase, string $saltHex): string
    {
        return sodium_crypto_pwhash(
            32,
            $passphrase,
            hex2bin($saltHex),
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /** 64 hex chars. */
    public static function sha256File(string $path): string
    {
        return hash_file('sha256', $path);
    }

    /** Stream-encrypt $in to $out; returns sha256 hex of the ciphertext file. */
    public static function encryptFile(string $key, string $in, string $out): string
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        $inH = fopen($in, 'rb');
        $outH = fopen($out, 'wb');
        fwrite($outH, $header);

        while (($block = fread($inH, self::CHUNK)) !== '' && $block !== false) {
            $last = strlen($block) < self::CHUNK;
            $tag = $last ? \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : 0;
            fwrite($outH, sodium_crypto_secretstream_xchacha20poly1305_push($state, $block, '', $tag));
            if ($last) {
                break;
            }
        }

        fclose($inH);
        fclose($outH);

        return self::sha256File($out);
    }

    /** Stream-decrypt $in to $out; throws on tamper/MAC failure. */
    public static function decryptFile(string $key, string $in, string $out): void
    {
        $inH = fopen($in, 'rb');
        $header = fread($inH, 24);
        if ($header === false || strlen($header) < 24) {
            fclose($inH);
            throw new RuntimeException('Decryption failed: truncated header');
        }

        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        } catch (\SodiumException $e) {
            fclose($inH);
            throw new RuntimeException('Decryption failed: ' . $e->getMessage());
        }
        $outH = fopen($out, 'wb');

        while (($ct = fread($inH, self::CHUNK + self::MAC)) !== '' && $ct !== false) {
            try {
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ct);
            } catch (\SodiumException $e) {
                fclose($inH);
                fclose($outH);
                throw new RuntimeException('Decryption failed: ' . $e->getMessage());
            }
            if ($result === false) {
                fclose($inH);
                fclose($outH);
                throw new RuntimeException('Decryption failed: corrupted chunk');
            }
            [$plain, $tag] = $result;
            fwrite($outH, $plain);
            if ($tag === \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }
        }

        fclose($inH);
        fclose($outH);
        sodium_memzero($key);
    }
}
