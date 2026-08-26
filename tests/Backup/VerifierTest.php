<?php declare(strict_types=1);

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Backup\BackupRunner;
use danog\MadelineProto\Backup\BackupSetConfig;
use danog\MadelineProto\Backup\BackupStore;
use danog\MadelineProto\Backup\InMemoryVault;
use danog\MadelineProto\Backup\Verifier;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class VerifierTest extends TestCase
{
    public function testVerifyRandomDetectsCorruption(): void
    {
        $dir = sys_get_temp_dir() . '/ver-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/f.bin', 'data');
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $store = new BackupStore($driver);
        $vault = new InMemoryVault();
        (new BackupRunner($vault, $store))->run('vault', new BackupSetConfig([$dir]), 'pw');

        // Corrupt the stored chunk in the fake vault:
        $propName = property_exists(InMemoryVault::class, 'data') ? 'data' : 'payloads';
        $ref = new ReflectionProperty(InMemoryVault::class, $propName);
        $map = $ref->getValue($vault);
        foreach ($map as $ch => $msgs) {
            foreach ($msgs as $mid => $row) {
                if (!str_contains($row[0], 'manifest')) {
                    $map[$ch][$mid][1] = 'CORRUPTED';
                }
            }
        }
        $ref->setValue($vault, $map);

        $result = (new Verifier($vault, $store))->verifyRandom(1);
        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, count($result['failed']));

        foreach (glob($dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($dir);
    }
}
