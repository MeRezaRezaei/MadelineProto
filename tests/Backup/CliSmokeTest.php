<?php declare(strict_types=1);

namespace danog\MadelineProto\Test;

use PHPUnit\Framework\TestCase;

class CliSmokeTest extends TestCase
{
    public function testBackupListRunsAgainstSqlite(): void
    {
        $tmp = sys_get_temp_dir() . '/cli-' . uniqid();
        mkdir($tmp);
        file_put_contents($tmp . '/cfg.json', '{"sets": {"vault": {"paths": ["/etc/hostname"]}}}');
        $bin = dirname(__DIR__, 2) . '/bin/madeline-daemon';
        exec(sprintf(
            'php %s backup:list --dsn=%s --config=%s 2>&1',
            escapeshellarg($bin),
            escapeshellarg('sqlite:' . $tmp . '/idx.sqlite'),
            escapeshellarg($tmp . '/cfg.json')
        ), $out, $code);
        unlink($tmp . '/cfg.json');
        @unlink($tmp . '/idx.sqlite');
        rmdir($tmp);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('vault', implode("\n", $out));
    }
}
