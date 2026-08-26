<?php declare(strict_types=1);

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Backup\Crypto;
use PHPUnit\Framework\TestCase;

class CryptoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crypto-test-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testRoundTrip(): void
    {
        $in = $this->dir . '/in.bin';
        $enc = $this->dir . '/enc.bin';
        $dec = $this->dir . '/dec.bin';
        file_put_contents($in, str_repeat('x', 200000)); // crosses several 64 KiB blocks
        $salt = Crypto::generateSalt();
        $this->assertSame(32, strlen($salt));
        $key = Crypto::deriveKey('correct horse', $salt);
        $hash = Crypto::encryptFile($key, $in, $enc);
        $this->assertSame(hash('sha256', (string) file_get_contents($enc)), $hash);
        Crypto::decryptFile($key, $enc, $dec);
        $this->assertSame(hash_file('sha256', $in), hash_file('sha256', $dec));
    }

    public function testTamperThrows(): void
    {
        $in = $this->dir . '/in.bin';
        $enc = $this->dir . '/enc.bin';
        file_put_contents($in, 'data');
        $key = Crypto::deriveKey('pass', Crypto::generateSalt());
        Crypto::encryptFile($key, $in, $enc);
        $raw = (string) file_get_contents($enc);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'A' ? 'B' : 'A';
        file_put_contents($enc, $raw);
        $this->expectException(\RuntimeException::class);
        Crypto::decryptFile($key, $enc, $this->dir . '/dec.bin');
    }

    public function testWrongPassphraseThrows(): void
    {
        $in = $this->dir . '/in2.bin';
        $enc = $this->dir . '/enc2.bin';
        file_put_contents($in, 'data');
        $salt = Crypto::generateSalt();
        Crypto::encryptFile(Crypto::deriveKey('right', $salt), $in, $enc);
        $this->expectException(\RuntimeException::class);
        Crypto::decryptFile(Crypto::deriveKey('wrong', $salt), $enc, $this->dir . '/dec2.bin');
    }
}
