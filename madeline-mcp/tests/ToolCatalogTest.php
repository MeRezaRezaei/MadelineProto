<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\ApiClient;
use MadelineMcp\McpServer;
use MadelineMcp\ToolCatalog;
use PHPUnit\Framework\TestCase;

final class ToolCatalogTest extends TestCase
{
    private function catalog(): ToolCatalog
    {
        return new ToolCatalog(new ApiClient('test-session'));
    }

    public function testAdvertisesExpectedTools(): void
    {
        $tools = $this->catalog()->all();
        $names = \array_column($tools, 'name');
        self::assertSame([
            'get_login_state',
            'get_me',
            'list_dialogs',
            'send_message',
            'read_history',
            'resolve_peer',
            'search_messages',
            'get_full_chat_info',
            'list_methods',
            'call_method',
        ], $names);
    }

    public function testEveryToolHasSchema(): void
    {
        foreach ($this->catalog()->all() as $tool) {
            self::assertArrayHasKey('name', $tool, \json_encode($tool));
            self::assertArrayHasKey('description', $tool);
            self::assertArrayHasKey('inputSchema', $tool);
            $schema = $tool['inputSchema'];
            self::assertSame('object', $schema['type']);
            self::assertArrayHasKey('properties', $schema);
        }
    }

    public function testUnknownToolReturnsError(): void
    {
        $result = $this->catalog()->call('nope', []);
        self::assertArrayHasKey('_error', $result);
    }

    public function testCallMethodRequiresName(): void
    {
        $result = $this->catalog()->call('call_method', []);
        self::assertArrayHasKey('_error', $result);
        self::assertSame('method is required', $result['message']);
    }
}