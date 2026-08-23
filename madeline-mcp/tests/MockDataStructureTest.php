<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Documents and validates the shape of the MCP mock/fixture data so that
 * future unit tests have a stable, account-independent reference structure.
 *
 * These fixtures mirror the JSON returned by the real Telegram/MadelineProto
 * methods and are safe to commit (no real credentials or personal data beyond
 * obviously-fake sample values).
 */
final class MockDataStructureTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = getcwd() . '/madeline-mcp/tests/fixtures';
    }

    private function load(string $file): mixed
    {
        $path = $this->dir . '/' . $file;
        self::assertFileExists($path);
        $data = \json_decode(\file_get_contents($path), true);
        self::assertIsArray($data, "$file must be valid JSON");
        return $data;
    }

    public function testGetMeShape(): void
    {
        $me = $this->load('get_me.json');
        self::assertArrayHasKey('id', $me);
        self::assertArrayHasKey('username', $me);
        self::assertArrayHasKey('first_name', $me);
        self::assertIsInt($me['id']);
    }

    public function testListAccountsShape(): void
    {
        $accounts = $this->load('list_accounts.json');
        $first = $accounts[0];
        self::assertArrayHasKey('session_name', $first);
        self::assertArrayHasKey('api_id', $first);
        self::assertArrayHasKey('state', $first);
        self::assertArrayHasKey('logged_in', $first);
        self::assertIsBool($first['logged_in']);
    }

    public function testListDialogsShape(): void
    {
        $dialogs = $this->load('list_dialogs.json');
        self::assertNotEmpty($dialogs);
        self::assertIsInt($dialogs[0]);
    }

    public function testContactsShape(): void
    {
        $res = $this->load('contacts.getContacts.json');
        self::assertArrayHasKey('contacts', $res);
        self::assertArrayHasKey('users', $res);
        self::assertIsArray($res['contacts']);
    }

    public function testMethodCatalogShape(): void
    {
        $catalog = $this->load('method_catalog_sample.json');
        foreach ($catalog as $method => $def) {
            self::assertIsString($method);
            self::assertArrayHasKey('type', $def);
            self::assertArrayHasKey('params', $def);
            self::assertIsArray($def['params']);
        }
    }
}
