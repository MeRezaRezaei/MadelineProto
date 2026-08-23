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
 *
 * Account configuration (api_id / api_hash) is intentionally NOT kept in any
 * external JSON file. It is persisted by MadelineProto itself inside each
 * session's own database (the sessions/<name>/ directory).
 *
 * A single "primary" session stores one Telegram *application* api key. Every
 * additional account inherits that same app key (Telegram apps use one
 * api_id/api_hash across many account logins), so the user only supplies the
 * key once. The list of accounts is simply the set of session directories on
 * disk, and each account's api key is read back from its session database.
 */
final class ApiClient
{
    private array $apis = [];

    /**
     * In-memory fallback for api keys supplied via add_account / env in the
     * current process. Only used until the session database has been written.
     */
    private array $configs = [];

    private string $defaultSession;
    private string $sessionsDir;

    /**
     * Absolute path to the sessions directory. cwd-independent: derived from
     * MADELINE_SESSION_DIR, else the repo-root/sessions next to this file.
     */
    public static function sessionDir(): string
    {
        $env = \getenv('MADELINE_SESSION_DIR');
        return ($env !== false && $env !== '') ? \rtrim($env, '/') : \dirname(__DIR__, 2) . '/sessions';
    }

    public function defaultSession(): string
    {
        return $this->defaultSession;
    }

    /** cwd-independent cache dir for scrapes/usage state. */
    public static function cacheDir(): string
    {
        $env = \getenv('MADELINE_CACHE_DIR');
        $dir = ($env !== false && $env !== '') ? \rtrim($env, '/') : \dirname(self::sessionDir()) . '/cache';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public function __construct(string $session = 'madeline-mcp')
    {
        $this->sessionsDir = self::sessionDir();

        if (!\is_dir($this->sessionsDir)) {
            @\mkdir($this->sessionsDir, 0755, true);
        }

        // Auto-detect: if the requested default session has no stored session,
        // fall back to the first existing one so single-account setups work
        // without passing session_name everywhere.
        if ($session === 'madeline-mcp' && !\is_dir($this->sessionsDir . '/madeline-mcp')) {
            foreach (\scandir($this->sessionsDir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..' && \is_dir($this->sessionsDir . '/' . $entry)) {
                    $session = $entry;
                    break;
                }
            }
        }
        $this->defaultSession = $session;

        // Backward-compatible environment variables for the default session.
        if (!isset($this->configs[$this->defaultSession])) {
            $apiId = (int) \getenv('API_ID');
            $apiHash = (string) \getenv('API_HASH');
            if ($apiId > 0 && $apiHash !== '') {
                $this->configs[$this->defaultSession] = [
                    'api_id' => $apiId,
                    'api_hash' => $apiHash,
                ];
            }
        }
    }

    /**
     * Register an account. If api_id / api_hash are omitted they are inherited
     * from the primary session database (one app key, many accounts). The
     * resolved keys are written into the account's MadelineProto session
     * database so they survive restarts; no external JSON registry is used.
     */
    public function addAccountConfig(
        string $sessionName,
        ?int $apiId = null,
        ?string $apiHash = null,
    ): void {
        [$apiId, $apiHash] = $this->resolveAppCredentials($apiId, $apiHash);

        $this->configs[$sessionName] = [
            'api_id' => $apiId,
            'api_hash' => $apiHash,
        ];

        // Persist into the MadelineProto session database. Constructing the API
        // with the AppInfo settings serializes the api keys into sessions/<name>/.
        $this->buildApi($sessionName, $apiId, $apiHash);
    }

    /**
     * Resolve the app api_id / api_hash, inheriting the single shared key from
     * the primary session database when not explicitly supplied.
     *
     * Precedence: explicit args -> default session DB -> env -> any session DB.
     */
    private function resolveAppCredentials(?int $apiId, ?string $apiHash): array
    {
        if ($apiId !== null && $apiHash !== null && $apiId > 0 && $apiHash !== '') {
            return [$apiId, $apiHash];
        }

        // 1) Default (primary) session database.
        $primaryPath = $this->sessionsDir . '/' . $this->defaultSession;
        if (\is_dir($primaryPath)) {
            try {
                $app = $this->api($this->defaultSession)->getSettings()->getAppInfo();
                if ($app->getApiId() > 0 && $app->getApiHash() !== '') {
                    return [$app->getApiId(), $app->getApiHash()];
                }
            } catch (Throwable $e) {
                // fall through
            }
        }

        // 2) Environment variables.
        $envId = (int) \getenv('API_ID');
        $envHash = (string) \getenv('API_HASH');
        if ($envId > 0 && $envHash !== '') {
            return [$envId, $envHash];
        }

        // 3) Any existing session database (they all share the same app key).
        foreach ($this->discoverSessions() as $name) {
            try {
                $app = $this->api($name)->getSettings()->getAppInfo();
                if ($app->getApiId() > 0 && $app->getApiHash() !== '') {
                    return [$app->getApiId(), $app->getApiHash()];
                }
            } catch (Throwable $e) {
                // skip sessions we cannot read
            }
        }

        throw new RuntimeException(
            "No api_id/api_hash supplied and no existing session database found. " .
            "Add the primary account first (or set API_ID/API_HASH)."
        );
    }

    /**
     * Enumerate every account from the MadelineProto session databases on disk.
     */
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->discoverSessions() as $sessionName) {
            try {
                $api = $this->api($sessionName);
                $appInfo = $api->getSettings()->getAppInfo();
                $auth = $api->getAuthorization();
                $me = $api->getSelf();
                $loggedIn = \is_array($me) && $auth === API::LOGGED_IN;
                $accounts[] = [
                    'session_name' => $sessionName,
                    'api_id' => $appInfo->getApiId(),
                    'state' => $this->authLabel($auth),
                    'logged_in' => $loggedIn,
                    'username' => $loggedIn ? ($me['username'] ?? null) : null,
                ];
            } catch (Throwable $e) {
                $accounts[] = [
                    'session_name' => $sessionName,
                    'state' => 'error',
                    'logged_in' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $accounts;
    }

    /** Names of every session directory that holds a MadelineProto database. */
    private function discoverSessions(): array
    {
        if (!\is_dir($this->sessionsDir)) {
            return [];
        }
        $names = [];
        foreach (\scandir($this->sessionsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->sessionsDir . '/' . $entry;
            if (!\is_dir($path)) {
                continue;
            }
            // Only treat directories that actually contain a MadelineProto
            // session (identified by its state / library files).
            if (
                \file_exists($path . '/safe.php')
                || \file_exists($path . '/ipcState.php')
                || \file_exists($path . '/lightState.php')
                || \file_exists($path . '/lock')
            ) {
                $names[] = $entry;
            }
        }
        return $names;
    }

    public function api(?string $sessionName = null): API
    {
        $sessionName ??= $this->defaultSession;

        if (isset($this->apis[$sessionName])) {
            return $this->apis[$sessionName];
        }

        $path = $this->sessionsDir . '/' . $sessionName;
        if (\is_dir($path)) {
            // Session database exists: load api keys from it (no external file).
            $api = new API($this->sessionsDir . '/' . $sessionName);
            $this->apis[$sessionName] = $api;
            return $api;
        }

        if (isset($this->configs[$sessionName])) {
            $cfg = $this->configs[$sessionName];
            return $this->buildApi($sessionName, (int) $cfg['api_id'], (string) $cfg['api_hash']);
        }

        throw new RuntimeException(
            "Account for session '$sessionName' is not configured. Use add_account first."
        );
    }

    private function buildApi(string $sessionName, int $apiId, string $apiHash): API
    {
        $appInfo = (new AppInfo())
            ->setApiId($apiId)
            ->setApiHash($apiHash);

        $settings = (new Settings())->setAppInfo($appInfo);

        $logLevel = (string) \getenv('LOG_LEVEL');
        if ($logLevel !== '') {
            $settings->setLogger((new Logger())->setLevel($logLevel));
        }

        $api = new API('sessions/' . $sessionName, $settings);
        $this->apis[$sessionName] = $api;
        return $api;
    }

    private function authLabel(int $auth): string
    {
        return match ($auth) {
            API::LOGGED_IN => 'LOGGED_IN',
            API::WAITING_CODE => 'WAITING_CODE',
            API::WAITING_PASSWORD => 'WAITING_PASSWORD',
            API::WAITING_SIGNUP => 'WAITING_SIGNUP',
            API::LOGGED_OUT => 'LOGGED_OUT',
            API::NOT_LOGGED_IN => 'NOT_LOGGED_IN',
            default => 'UNKNOWN',
        };
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
