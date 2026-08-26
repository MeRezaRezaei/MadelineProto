<?php declare(strict_types=1);

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Backup\BackupConfig;
use PHPUnit\Framework\TestCase;

class BackupConfigTest extends TestCase
{
    public function testLoadFileParsesAndValidates(): void
    {
        $tmp = sys_get_temp_dir() . '/bc-' . uniqid() . '.json';
        file_put_contents($tmp, json_encode([
            'sets' => [
                'vault' => ['paths' => ['/etc/madeline'], 'exclude' => ['*.tmp']],
                'db' => ['paths' => ['/var/backups/db'], 'pre_command' => 'pg_dump x > /var/backups/db/d.sql'],
            ],
        ]));
        $cfg = BackupConfig::loadFile($tmp);
        unlink($tmp);
        $this->assertTrue($cfg->has('vault'));
        $this->assertTrue($cfg->set('db')->matchesExclude('nope'));
        $this->assertFalse($cfg->set('vault')->matchesExclude('foo.tmp'));
    }

    public function testUnknownSetThrows(): void
    {
        $cfg = new BackupConfig([]);
        $this->expectException(\InvalidArgumentException::class);
        $cfg->set('nope');
    }

    public function testRelativePathRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \danog\MadelineProto\Backup\BackupSetConfig(['relative/path']);
    }

    public function testUnknownKeyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BackupConfig::fromArray(['sets' => ['a' => ['paths' => ['/x'], 'bogus' => 1]]]);
    }
}
