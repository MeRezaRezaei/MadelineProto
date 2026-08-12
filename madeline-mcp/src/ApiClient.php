<?php

declare(strict_types=1);

namespace MadelineMcp;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger;
use Throwable;

/**
 * Thin wrapper around a MadelineProto API instance.
 *
 * Bootstraps from environment variables:
 *   API_ID, API_HASH, BOT_TOKEN (optional), SESSION (optional session file name).
 */
final class ApiClient
{
    private ?API $api = null;

    public function __construct(
        private readonly string $session = 'madeline-mcp',
    ) {
    }

    private function appInfo(): AppInfo
    {
        $apiId = (int) getenv('API_ID');
        $apiHash = (string) getenv('API_HASH');
        if ($apiId === 0 || $apiHash === '') {
            throw new \RuntimeException('Set API_ID and API_HASH environment variables.');
        }
        return (new AppInfo())->setApiId($apiId)->setApiHash($apiHash);
    }

    private function settings(): Settings
    {
        $settings = (new Settings())
            ->setAppInfo($this->appInfo());
        $logLevel = (string) getenv('LOG_LEVEL');
        if ($logLevel !== '') {
            $settings->setLogger((new Logger())->setLevel($logLevel));
        }
        return $settings;
    }

    public function api(): API
    {
        if ($this->api === null) {
            $this->api = new API($this->session, $this->settings());
        }
        return $this->api;
    }

    /**
     * Invoke any MTProto method by dotted name, e.g. "messages.sendMessage".
     *
     * @param array<string, mixed> $args
     */
    public function call(string $method, array $args): mixed
    {
        return $this->api()
            ->methodCallAsyncRead($method, $args)
            ->await();
    }
}