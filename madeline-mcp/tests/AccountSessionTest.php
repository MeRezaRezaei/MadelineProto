<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\ApiClient;
use MadelineMcp\ToolCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Validates the MCP server against a real, already-authenticated session.
 *
 * This test intentionally asserts on the *shape* of the data (not specific
 * personal identifiers) so it works for any logged-in account and never
 * hardcodes someone's Telegram id / username. Skips if the session is absent.
 */
final class AccountSessionTest extends TestCase
{
    private const SESSION = 'main_account';

    private ToolCatalog $catalog;

    protected function setUp(): void
    {
        $sessionDir = getcwd() . '/sessions/' . self::SESSION;
        if (!\is_dir($sessionDir)) {
            self::markTestSkipped('The "' . self::SESSION . '" session is not present; run the login flow first.');
        }

        $client = new ApiClient(self::SESSION);
        $this->catalog = new ToolCatalog($client);
    }

    public function testSessionIsLoggedIn(): void
    {
        $accounts = $this->catalog->call('list_accounts', []);
        $match = null;
        foreach ($accounts as $acc) {
            if (($acc['session_name'] ?? null) === self::SESSION) {
                $match = $acc;
                break;
            }
        }
        self::assertNotNull($match, 'main_account should be listed.');
        self::assertTrue($match['logged_in'], 'main_account should be logged in.');
        self::assertIsString($match['username'] ?? null);
        self::assertNotEmpty($match['username']);
    }

    public function testGetMeReturnsRealAccount(): void
    {
        $me = $this->catalog->call('get_me', ['session_name' => self::SESSION]);
        self::assertArrayNotHasKey('_error', $me, 'get_me should not error: ' . \json_encode($me));
        self::assertIsInt($me['id'] ?? null);
        self::assertGreaterThan(0, $me['id']);
        self::assertIsString($me['username'] ?? null);
        self::assertNotEmpty($me['username']);
        self::assertIsString($me['first_name'] ?? null);
    }

    public function testListDialogsWorks(): void
    {
        $dialogs = $this->catalog->call('list_dialogs', ['session_name' => self::SESSION, 'limit' => 5]);
        self::assertArrayNotHasKey('_error', $dialogs, 'list_dialogs should not error: ' . \json_encode($dialogs));
        self::assertIsArray($dialogs);
    }

    public function testRawCallMethodWorks(): void
    {
        $res = $this->catalog->call('call_method', [
            'session_name' => self::SESSION,
            'method' => 'contacts.getContacts',
            'args' => ['hash' => '0'],
        ]);
        self::assertArrayNotHasKey('_error', $res, 'call_method should not error: ' . \json_encode($res));
        self::assertArrayHasKey('contacts', $res);
    }
}
