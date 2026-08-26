<?php declare(strict_types=1);

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Backup\BackupRunner;
use danog\MadelineProto\Backup\BackupSetConfig;
use danog\MadelineProto\Backup\BackupStore;
use danog\MadelineProto\Backup\InMemoryVault;
use danog\MadelineProto\Backup\Restorer;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class RestorerTest extends TestCase
{
    private string $dir;
    private string $out;
    private InMemoryVault $vault;
    private BackupStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/restorer-' . uniqid();
        mkdir($this->dir . '/sub', 0777, true);
        file_put_contents($this->dir . '/a.txt', 'alpha');
        file_put_contents($this->dir . '/sub/b.bin', random_bytes(250000));
        $this->out = sys_get_temp_dir() . '/restored-' . uniqid();
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->store = new BackupStore($driver);
        $this->vault = new InMemoryVault();
        (new BackupRunner($this->vault, $this->store))->run('vault', new BackupSetConfig([$this->dir], chunkSize: 100000), 'pw');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, $this->out] as $d) {
            if (!is_dir($d)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($d);
        }
    }

    public function testRestoreRoundTrip(): void
    {
        $r = new Restorer($this->vault, $this->store);
        $n = $r->restore('vault', $this->out, 'pw');
        $this->assertSame(2, $n);
        $this->assertSame('alpha', file_get_contents($this->out . '/a.txt'));
        $this->assertSame(hash_file('sha256', $this->dir . '/sub/b.bin'), hash_file('sha256', $this->out . '/sub/b.bin'));
    }

    public function testIndexLossRecoveryFromManifestJson(): void
    {
        $manifestJson = array_values($this->vault->manifests())[0][1];
        // Fresh empty index — only the manifest + passphrase survive.
        $fresh = new BackupStore(new PdoDriver('sqlite::memory:'));
        (new Migrations($fresh->getDriver()))->migrate();
        $r = new Restorer($this->vault, $fresh);
        $n = $r->restoreFromManifestJson($manifestJson, $this->out, 'pw');
        $this->assertSame(2, $n);
        $this->assertSame('alpha', file_get_contents($this->out . '/a.txt'));
    }

    public function testWrongPassphraseFails(): void
    {
        $r = new Restorer($this->vault, $this->store);
        $this->expectException(RuntimeException::class);
        $r->restore('vault', $this->out, 'wrong');
    }
}
