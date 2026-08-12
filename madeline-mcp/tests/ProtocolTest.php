<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\ApiClient;
use MadelineMcp\McpServer;
use MadelineMcp\ToolCatalog;
use PHPUnit\Framework\TestCase;

final class ProtocolTest extends TestCase
{
    private function server(): McpServer
    {
        $client = new ApiClient('test-session');
        return new McpServer($client, new ToolCatalog($client));
    }

    public function testInitializeEchoesProtocolVersionConstant(): void
    {
        $resp = $this->server()->processLine(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
        );
        self::assertSame(McpServer::PROTOCOL_VERSION, $resp['result']['protocolVersion']);
        self::assertSame('madeline-mcp', $resp['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $resp['result']['capabilities']);
    }

    public function testToolsCallGetLoginStateReturnsErrorShapeWhenNoCreds(): void
    {
        $resp = $this->server()->processLine(
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"get_login_state","arguments":{}}}'
        );
        $text = $resp['result']['content'][0]['text'];
        self::assertStringContainsString('_error', $text);
        self::assertStringContainsString('API_ID', $text);
    }

    public function testToolsCallGetMeReturnsErrorShapeWhenNoCreds(): void
    {
        $resp = $this->server()->processLine(
            '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_me","arguments":{}}}'
        );
        self::assertStringContainsString('_error', $resp['result']['content'][0]['text']);
    }

    public function testToolsCallListMethodsNamespaced(): void
    {
        $resp = $this->server()->processLine(
            '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"list_methods","arguments":{"namespace":"unknown.ns"} }}'
        );
        self::assertSame(-32603, $resp['error']['code']);
    }

    public function testToolsCallInvalidArgs(): void
    {
        $resp = $this->server()->processLine(
            '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"send_message","arguments":"not-an-object"}}'
        );
        self::assertSame(-32602, $resp['error']['code']);
    }

    public function testToolsCallMissingName(): void
    {
        $resp = $this->server()->processLine(
            '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"arguments":{}}}'
        );
        self::assertSame(-32602, $resp['error']['code']);
    }

    public function testDuplicateIdsAreHandledIndependently(): void
    {
        $server = $this->server();
        $a = $server->processLine('{"jsonrpc":"2.0","id":7,"method":"ping"}');
        $b = $server->processLine('{"jsonrpc":"2.0","id":8,"method":"ping"}');
        self::assertSame(7, $a['id']);
        self::assertSame(8, $b['id']);
    }
}
