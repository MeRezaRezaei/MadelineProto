<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Test;

use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use function Amp\Redis\createRedisClient;
use danog\MadelineProto\Db\Cache;
use PHPUnit\Framework\TestCase;

/**
 * Cache acceptance tests (Redis on tcp://127.0.0.1:16379, no auth).
 */
class CacheTest extends TestCase
{
    private const DSN = 'tcp://127.0.0.1:16379';

    private Cache $cache;
    private RedisClient $raw;
    private string $prefix;

    protected function setUp(): void
    {
        try {
            $this->raw = createRedisClient(RedisConfig::fromUri(self::DSN));
            $this->raw->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable at ' . self::DSN . ': ' . $e->getMessage());
        }

        $this->prefix = 'mp-test-' . bin2hex(random_bytes(4)) . ':';
        $this->cache = new Cache($this->raw, $this->prefix);
    }

    public function testSetGetRoundTrip(): void
    {
        $this->cache->set('foo', 'bar')->await();
        $this->assertSame('bar', $this->cache->get('foo')->await());
    }

    public function testGetMissingReturnsNull(): void
    {
        $this->assertNull($this->cache->get('does-not-exist')->await());
    }

    public function testNamespacingIsExact(): void
    {
        // Cache with NO prefix so the raw key equals the canonical namespace.
        $bare = new Cache($this->raw, '');
        $key = Cache::userKey(42);
        $this->assertSame('entity:user:42', $key);

        $bare->set($key, 'alice')->await();
        // The actual stored key is exactly the namespaced string.
        $this->assertSame('alice', $this->raw->get($key));

        // A different logical key must not collide.
        $other = Cache::peerKey('alice_wonder');
        $this->assertNull($this->raw->get($other));
    }

    public function testPrefixIsolation(): void
    {
        $a = new Cache($this->raw, 'scope-a');
        $b = new Cache($this->raw, 'scope-b');

        $a->set('k', 'va')->await();
        $b->set('k', 'vb')->await();

        $this->assertSame('va', $a->get('k')->await());
        $this->assertSame('vb', $b->get('k')->await());

        $a->delete('k')->await();
        $this->assertNull($a->get('k')->await());
        $this->assertSame('vb', $b->get('k')->await());
    }

    public function testTtlExpiry(): void
    {
        $this->cache->set('temp', 'value', 1)->await();
        $this->assertSame('value', $this->cache->get('temp')->await());

        sleep(2);

        $this->assertNull($this->cache->get('temp')->await());
    }

    public function testDeleteRemovesKey(): void
    {
        $this->cache->set('gone', 'soon')->await();
        $this->assertSame('soon', $this->cache->get('gone')->await());

        $this->cache->delete('gone')->await();
        $this->assertNull($this->cache->get('gone')->await());
    }

    public function testExists(): void
    {
        $this->assertFalse($this->cache->exists('present')->await());

        $this->cache->set('present', 'yes')->await();
        $this->assertTrue($this->cache->exists('present')->await());

        $this->cache->delete('present')->await();
        $this->assertFalse($this->cache->exists('present')->await());
    }
}
