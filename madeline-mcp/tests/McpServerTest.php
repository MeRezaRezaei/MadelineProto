<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\ApiClient;
use MadelineMcp\McpServer;
use MadelineMcp\ToolCatalog;
use PHPUnit\Framework\TestCase;

final class McpServerTest extends TestCase
{
    private function server(): McpServer
    {
        $client = new ApiClient('test-session');
        return new McpServer($client, new ToolCatalog($client));
    }

    public function testInitialize(): void
    {
        $resp = $this->server()->processLine(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
        );
        self::assertSame('2.0', $resp['jsonrpc']);
        self::assertSame(1, $resp['id']);
        self::assertSame(McpServer::PROTOCOL_VERSION, $resp['result']['protocolVersion']);
        self::assertArrayHasKey('tools', $resp['result']['capabilities']);
    }

    public function testPing(): void
    {
        $resp = $this->server()->processLine('{"jsonrpc":"2.0","id":2,"method":"ping"}');
        self::assertSame(2, $resp['id']);
        self::assertArrayHasKey('result', $resp);
    }

    public function testInitializedNotificationHasNoResponse(): void
    {
        $resp = $this->server()->processLine('{"jsonrpc":"2.0","method":"notifications/initialized"}');
        self::assertNull($resp);
    }

    public function testToolsList(): void
    {
        $resp = $this->server()->processLine('{"jsonrpc":"2.0","id":3,"method":"tools/list"}');
        self::assertSame(10, \count($resp['result']['tools']));
    }

    public function testToolsCallUnknownTool(): void
    {
        $resp = $this->server()->processLine(
            '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"nope","arguments":{}}}',
        );
        $content = $resp['result']['content'][0]['text'];
        self::assertStringContainsString('_error', $content);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $resp = $this->server()->processLine('{"jsonrpc":"2.0","id":5,"method":"bogus"}');
        self::assertSame(-32601, $resp['error']['code']);
    }

    public function testParseError(): void
    {
        $resp = $this->server()->processLine('not json{');
        self::assertSame(-32700, $resp['error']['code']);
    }
}