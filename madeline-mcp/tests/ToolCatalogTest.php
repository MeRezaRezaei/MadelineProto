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
        $tools = $this->catalog()->all('all');
        $names = \array_column($tools, 'name');
        // The 18 ergonomic tools must still be present.
        foreach ([
            'list_accounts','add_account','start_login','submit_login_code',
            'submit_password','get_login_state','get_me','list_dialogs',
            'send_message','send_media','download_media','delete_messages',
            'read_history','resolve_peer','search_messages','get_full_chat_info',
            'list_methods','call_method',
        ] as $core) {
            self::assertContains($core, $names, "missing core tool $core");
        }
        // Settings layer must mirror Telegram (DDD: namespace = bounded context).
        self::assertContains('account.updateProfile', $names);
        self::assertContains('account.setPrivacy', $names);
        self::assertContains('messages.getPeerSettings', $names);
        self::assertContains('auth.logOut', $names);
        self::assertContains('account.deleteAccount', $names);
        self::assertContains('session.remove_account', $names);
        // Limits & budgeting layer
        self::assertContains('session.get_limits', $names);
        self::assertContains('session.get_quota', $names);
        self::assertContains('session.check_spam_status', $names);
        self::assertContains('session.get_cooldowns', $names);
        // Generic bot-driving layer
        self::assertContains('bots.list', $names);
        self::assertContains('bot.scan', $names);
        self::assertContains('bot.invoke', $names);
        self::assertGreaterThan(18, \count($names));
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

    public function testDefaultModeIsCompatibleAndHidesRawLayer(): void
    {
        $names = \array_column($this->catalog()->all(), 'name');
        self::assertContains('list_conversations', $names);
        self::assertContains('set_tool_mode', $names);
        self::assertNotContains('account.updateProfile', $names, 'raw settings layer must be hidden in compatible mode');
        self::assertNotContains('call_method', $names, 'raw method layer must be hidden in compatible mode');
    }

    public function testAllModeExposesRawLayer(): void
    {
        $names = \array_column($this->catalog()->all('all'), 'name');
        self::assertContains('account.updateProfile', $names);
        self::assertContains('call_method', $names);
        self::assertContains('list_conversations', $names);
    }

    public function testAdvancedModeShowsRawLayerOnly(): void
    {
        $names = \array_column($this->catalog()->all('advanced'), 'name');
        self::assertContains('call_method', $names);
        self::assertContains('set_tool_mode', $names);
        self::assertNotContains('list_conversations', $names, 'compatible tools hidden in advanced mode');
    }
}