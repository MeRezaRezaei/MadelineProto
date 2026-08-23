<?php

declare(strict_types=1);

namespace MadelineMcp;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger;
use Throwable;
use RuntimeException;

/**
 * Manages multiple MadelineProto API instances.
 */
final class ApiClient
{
    private array $apis = [];
    private array $configs = [];
    private string $registryPath;
    private string $defaultSession;

    public function __construct(string $session = 'madeline-mcp')
    {
        $this->defaultSession = $session;
        // Keep registry file alongside the app or in the current working dir
        $this->registryPath = getcwd() . '/madeline-mcp-accounts.json';
        
        $this->loadRegistry();

        // Support backward-compatible environment variables for the default session
        if (!isset($this->configs[$this->defaultSession])) {
            $apiId = (int) getenv('API_ID');
            $apiHash = (string) getenv('API_HASH');
            if ($apiId > 0 && $apiHash !== '') {
                $this->configs[$this->defaultSession] = [
                    'api_id' => $apiId,
                    'api_hash' => $apiHash,
                ];
                $this->saveRegistry();
            }
        }
    }

    private function loadRegistry(): void
    {
        if (\file_exists($this->registryPath)) {
            $data = \json_decode(\file_get_contents($this->registryPath), true);
            if (\is_array($data)) {
                $this->configs = $data;
            }
        }
    }

    private function saveRegistry(): void
    {
        \file_put_contents($this->registryPath, \json_encode($this->configs, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    public function addAccountConfig(string $sessionName, int $apiId, string $apiHash): void
    {
        $this->configs[$sessionName] = [
            'api_id' => $apiId,
            'api_hash' => $apiHash,
        ];
        $this->saveRegistry();
    }

    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configs as $sessionName => $config) {
            try {
                $api = $this->api($sessionName);
                $me = $api->getSelf();
                $loggedIn = \is_array($me);
                $accounts[] = [
                    'session_name' => $sessionName,
                    'api_id' => $config['api_id'],
                    'state' => $loggedIn ? 'LOGGED_IN' : 'NOT_LOGGED_IN',
                    'logged_in' => $loggedIn,
                    'username' => $loggedIn ? ($me['username'] ?? null) : null,
                ];
            } catch (Throwable $e) {
                $accounts[] = [
                    'session_name' => $sessionName,
                    'api_id' => $config['api_id'],
                    'state' => 'error',
                    'logged_in' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        return $accounts;
    }

    public function api(?string $sessionName = null): API
    {
        $sessionName ??= $this->defaultSession;

        if (isset($this->apis[$sessionName])) {
            return $this->apis[$sessionName];
        }

        if (!isset($this->configs[$sessionName])) {
            throw new RuntimeException("Account for session '$sessionName' is not configured. Use add_account first.");
        }

        $config = $this->configs[$sessionName];
        $appInfo = (new AppInfo())
            ->setApiId((int) $config['api_id'])
            ->setApiHash((string) $config['api_hash']);

        $settings = (new Settings())->setAppInfo($appInfo);
        
        $logLevel = (string) getenv('LOG_LEVEL');
        if ($logLevel !== '') {
            $settings->setLogger((new Logger())->setLevel($logLevel));
        }

        $base = getcwd() . '/sessions';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $api = new API('sessions/' . $sessionName, $settings);
        $this->apis[$sessionName] = $api;

        return $api;
    }

    /**
     * Invoke any MTProto method by dotted name (e.g. "users.getUsers").
     */
    public function call(string $method, array $args, ?string $sessionName = null): mixed
    {
        $api = $this->api($sessionName);
        if (!\str_contains($method, '.')) {
            // Namespace-less method: route through the generic caller if available.
            if (\method_exists($api, 'methodCallAsyncRead')) {
                return $api->methodCallAsyncRead($method, $args)->await();
            }
            return $api->__call('methodCallAsyncRead', [$method, $args])->await();
        }
        [$ns, $fn] = \explode('.', $method, 2);
        return $api->{$ns}->{$fn}($args);
    }
}
