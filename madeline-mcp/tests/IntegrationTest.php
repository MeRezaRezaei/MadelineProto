<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\ApiClient;
use MadelineMcp\ToolCatalog;
use PHPUnit\Framework\TestCase;

final class IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $apiId = getenv('API_ID');
        $apiHash = getenv('API_HASH');
        if (!is_string($apiId) || $apiId === '' || !is_string($apiHash) || $apiHash === '') {
            self::markTestSkipped('Set API_ID and API_HASH to run integration tests.');
        }
    }

    public function testLoginAndCatalog(): void
    {
        $client = new ApiClient('/tmp/mcp-it-session');
        $catalog = new ToolCatalog($client);

        $state = $catalog->call('get_login_state', []);
        $this->assertArrayHasKey('state', $state);

        $token = getenv('BOT_TOKEN');
        if (is_string($token) && $token !== '') {
            $me = $catalog->call('get_me', []);
            $this->assertNotSame(['_error' => true], $me, 'Expected bot login to succeed.');
        }

        $methods = $catalog->call('list_methods', ['namespace' => 'account']);
        $this->assertIsArray($methods);
        $this->assertGreaterThan(50, count($methods));
        $this->assertArrayHasKey('account.getNotifyExceptions', $methods);
    }
}
