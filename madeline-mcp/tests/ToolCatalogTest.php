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
}