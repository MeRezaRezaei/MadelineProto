<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\MTProto\TL;

use RuntimeException;

/**
 * Basic TL (Type Language) binary parser & packer.
 */
class TLSerializer
{
    public static function packString(string $s): string
    {
        $len = strlen($s);
        if ($len <= 253) {
            $packed = chr($len) . $s;
            $pad = (4 - (($len + 1) % 4)) % 4;
            return $packed . str_repeat("\x00", $pad);
        }

        $packed = "\xfe" . chr($len & 0xff) . chr(($len >> 8) & 0xff) . chr(($len >> 16) & 0xff) . $s;
        $pad = (4 - (($len + 4) % 4)) % 4;
        return $packed . str_repeat("\x00", $pad);
    }

    public static function unpackString(string $data, int &$offset = 0): string
    {
        $first = ord($data[$offset++]);
        if ($first <= 253) {
            $len = $first;
            $str = substr($data, $offset, $len);
            $offset += $len;
            $pad = (4 - (($len + 1) % 4)) % 4;
            $offset += $pad;
            return $str;
        }

        $len = ord($data[$offset]) | (ord($data[$offset + 1]) << 8) | (ord($data[$offset + 2]) << 16);
        $offset += 3;
        $str = substr($data, $offset, $len);
        $offset += $len;
        $pad = (4 - (($len + 4) % 4)) % 4;
        $offset += $pad;

        return $str;
    }

    public static function packInt(int $i): string
    {
        return pack('l', $i);
    }

    public static function packLong(int $i): string
    {
        return pack('q', $i);
    }
}
