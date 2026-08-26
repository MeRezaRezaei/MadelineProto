<?php declare(strict_types=1);

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Backup\Chunker;
use PHPUnit\Framework\TestCase;

class ChunkerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/chunk-test-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testSplitAndReassemble(): void
    {
        $data = str_repeat(pack('N', 0), 1024) . 'tail'; // 4096+4 bytes
        $file = $this->dir . '/big.bin';
        file_put_contents($file, $data);
        $chunks = Chunker::split($file, 1000, $this->dir);
        $this->assertCount(5, $chunks);
        $out = '';
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(1000, $c['size']);
            $this->assertSame(strlen((string) file_get_contents($c['tmpPath'])), $c['size']);
            $out .= file_get_contents($c['tmpPath']);
        }
        $this->assertSame($data, $out);
    }

    public function testEmptyFileYieldsNoChunks(): void
    {
        $file = $this->dir . '/empty.bin';
        file_put_contents($file, '');
        $this->assertSame([], Chunker::split($file, 1000, $this->dir));
    }

    public function testSmallFileSingleChunk(): void
    {
        $file = $this->dir . '/small.bin';
        file_put_contents($file, 'abc');
        $chunks = Chunker::split($file, 1000, $this->dir);
        $this->assertCount(1, $chunks);
        $this->assertSame(3, $chunks[0]['size']);
    }
}
