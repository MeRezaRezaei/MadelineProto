<?php declare(strict_types=1);

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Backup\BackupRunner;
use danog\MadelineProto\Backup\BackupSetConfig;
use danog\MadelineProto\Backup\BackupStore;
use danog\MadelineProto\Backup\InMemoryVault;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BackupRunnerTest extends TestCase
{
    private string $dir;
    private InMemoryVault $vault;
    private BackupStore $store;
    private BackupRunner $runner;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runner-' . uniqid();
        mkdir($this->dir . '/sub', 0777, true);
        file_put_contents($this->dir . '/a.txt', 'alpha');
        file_put_contents($this->dir . '/sub/b.txt', str_repeat('b', 250000));
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->store = new BackupStore($driver);
        $this->vault = new InMemoryVault();
        $this->runner = new BackupRunner($this->vault, $this->store);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
    }

    public function testFullRunDedupAndManifest(): void
    {
        $set = new BackupSetConfig([$this->dir], chunkSize: 100000);
        $snap = $this->runner->run('vault', $set, 'pw');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $snap);
        $this->assertSame(4, count($this->vault->chunks())); // a.txt(1) + b.txt(3)
        $this->assertCount(1, $this->vault->manifests());
        $manifest = json_decode(array_values($this->vault->manifests())[0][1], true);
        $this->assertSame(-1000000000001, $manifest['channel_id']);

        // Order-independent file lookup (directory iteration order is filesystem-dependent):
        $bFile = null;
        foreach ($manifest['files'] as $f) {
            if ($f['path'] === 'sub/b.txt') {
                $bFile = $f;
            }
        }
        $this->assertNotNull($bFile);
        $this->assertCount(3, $bFile['chunks']);

        // no-change re-run: pure dedup, no new uploads
        $this->runner->run('vault', $set, 'pw');
        $this->assertSame(4, count($this->vault->chunks()));

        // change one file: exactly one new chunk
        file_put_contents($this->dir . '/a.txt', 'alphb');
        touch($this->dir . '/a.txt', time() + 5);
        $this->runner->run('vault', $set, 'pw');
        $this->assertSame(5, count($this->vault->chunks()));
        $this->assertCount(3, $this->store->listSnapshots('vault'));
    }

    public function testPreCommandFailureAborts(): void
    {
        $set = new BackupSetConfig([$this->dir], preCommand: 'exit 3');
        $this->expectException(\RuntimeException::class);
        $this->runner->run('vault', $set, 'pw');
    }
}
