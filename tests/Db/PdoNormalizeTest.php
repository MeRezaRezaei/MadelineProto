<?php declare(strict_types=1);

namespace danog\MadelineProto\Db;

use PHPUnit\Framework\TestCase;

final class PdoNormalizeTest extends TestCase
{
    public function testPostgresUrlRoundTrips(): void
    {
        $dsn = 'postgres://user:pass@host:5432/db';
        $expected = 'pgsql:host=host;port=5432;dbname=db;user=user;password=pass';
        $this->assertSame($expected, PdoDriver::normalizeDsn($dsn));
    }

    public function testSqlitePassThrough(): void
    {
        $dsn = 'sqlite::memory:';
        $this->assertSame($dsn, PdoDriver::normalizeDsn($dsn));
    }
}
