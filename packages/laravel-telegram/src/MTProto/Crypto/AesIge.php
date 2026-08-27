<?php

declare(strict_types=1);

namespace MeRezaRezaei\LaravelTelegram\MTProto\Crypto;

use RuntimeException;

/**
 * AES-256-IGE Cipher implementation.
 * Used for MTProto 2.0 packet encryption and decryption.
 */
class AesIge
{
    public static function encrypt(string $data, string $key, string $iv): string
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('AES key must be 32 bytes for AES-256');
        }
        if (strlen($iv) !== 32) {
            throw new RuntimeException('AES IV must be 32 bytes for AES-IGE');
        }

        $iv1 = substr($iv, 0, 16);
        $iv2 = substr($iv, 16, 16);

        $len = strlen($data);
        if ($len % 16 !== 0) {
            $data .= str_repeat("\x00", 16 - ($len % 16));
            $len = strlen($data);
        }

        $cipher = '';
        for ($i = 0; $i < $len; $i += 16) {
            $block = substr($data, $i, 16);
            $xor = $block ^ $iv1;
            $encrypted = openssl_encrypt($xor, 'aes-256-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING);
            $c = $encrypted ^ $iv2;
            $cipher .= $c;
            $iv1 = $c;
            $iv2 = $block;
        }

        return $cipher;
    }

    public static function decrypt(string $cipher, string $key, string $iv): string
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('AES key must be 32 bytes for AES-256');
        }
        if (strlen($iv) !== 32) {
            throw new RuntimeException('AES IV must be 32 bytes for AES-IGE');
        }

        $iv1 = substr($iv, 0, 16);
        $iv2 = substr($iv, 16, 16);

        $len = strlen($cipher);
        if ($len % 16 !== 0) {
            throw new RuntimeException('Cipher length must be multiple of 16');
        }

        $plain = '';
        for ($i = 0; $i < $len; $i += 16) {
            $c = substr($cipher, $i, 16);
            $xor = $c ^ $iv2;
            $decrypted = openssl_decrypt($xor, 'aes-256-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING);
            $p = $decrypted ^ $iv1;
            $plain .= $p;
            $iv1 = $c;
            $iv2 = $p;
        }

        return $plain;
    }
}
