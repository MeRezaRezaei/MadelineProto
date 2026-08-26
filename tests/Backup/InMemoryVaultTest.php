<?php declare(strict_types=1);

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Backup\InMemoryVault;
use PHPUnit\Framework\TestCase;

class InMemoryVaultTest extends TestCase
{
    public function testEnsureChannelIsStablePerSet(): void
    {
        $vault = new InMemoryVault();
        $a = $vault->ensureChannel('vault');
        $this->assertSame($a, $vault->ensureChannel('vault'));
        $this->assertNotSame($a, $vault->ensureChannel('db'));
    }

    public function testChunkRoundTrip(): void
    {
        $vault = new InMemoryVault();
        $ch = $vault->ensureChannel('vault');
        $tmp = sys_get_temp_dir() . '/inv-' . uniqid() . '.bin';
        file_put_contents($tmp, 'hello');
        [$msgId, $fileId] = $vault->uploadChunk($ch, str_repeat('a', 64), $tmp);
        $this->assertGreaterThan(0, $msgId);
        $this->assertSame('fake:' . str_repeat('a', 64), $fileId);

        $dest = sys_get_temp_dir() . '/inv-' . uniqid() . '.out';
        $vault->downloadChunk($ch, $msgId, $dest);
        $this->assertSame('hello', file_get_contents($dest));
        unlink($tmp);
        unlink($dest);
    }

    public function testManifestRoundTrip(): void
    {
        $vault = new InMemoryVault();
        $ch = $vault->ensureChannel('vault');
        $msgId = $vault->uploadManifest($ch, 'snap1', '{"ok": true}');
        $this->assertSame('{"ok": true}', $vault->downloadManifest($ch, $msgId));
    }
}
