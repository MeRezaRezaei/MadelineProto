<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Tests;

use Danog\LaravelTelegram\MTProto\Crypto\AesIge;
use Danog\LaravelTelegram\MTProto\Crypto\PasswordCalculator;
use Danog\LaravelTelegram\MTProto\TL\TLSerializer;
use PHPUnit\Framework\TestCase;

class MtprotoCryptoAndTlTest extends TestCase
{
    public function testAesIgeEncryptAndDecrypt(): void
    {
        $key = random_bytes(32);
        $iv = random_bytes(32);
        $plaintext = 'Secret Telegram MTProto message payload 1234567890!';

        $cipher = AesIge::encrypt($plaintext, $key, $iv);
        $decrypted = AesIge::decrypt($cipher, $key, $iv);

        // Strip padding null bytes
        $this->assertEquals($plaintext, rtrim($decrypted, "\x00"));
    }

    public function testTLStringPackingAndUnpacking(): void
    {
        $testStrings = [
            'short',
            'Hello world! A bit longer string that spans several words.',
            str_repeat('A', 300), // > 253 bytes to trigger 4-byte length prefix
        ];

        foreach ($testStrings as $original) {
            $packed = TLSerializer::packString($original);
            $offset = 0;
            $unpacked = TLSerializer::unpackString($packed, $offset);

            $this->assertEquals($original, $unpacked);
            $this->assertEquals(strlen($packed), $offset);
        }
    }
}
