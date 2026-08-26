<?php

declare(strict_types=1);

namespace MadelineMcp;

use danog\MadelineProto\API;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Db\SqlDriver;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Database\Memory;
use danog\MadelineProto\Settings\Database\Postgres;
use danog\MadelineProto\Settings\DatabaseAbstract;
use danog\MadelineProto\Settings\Logger;
use RuntimeException;
use Throwable;

/**
 * Manages multiple MadelineProto API instances.
 *
 * Supports PostgreSQL / SQLite RelationalStore as the primary single source of truth
 * for accounts, credentials, and entity resolution, while maintaining backward
 * compatibility with flat-file session directories.
 */
final class ApiClient
{
    private array $apis = [];

    /**
     * In-memory fallback for api keys supplied via add_account / env in the
     * current process.
     */
    private array $configs = [];

    private string $defaultSession;
    private string $sessionsDir;

    private ?RelationalStore $store = null;
    private ?SqlDriver $driver = null;
    private ?string $dsn = null;

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

    public function __construct(
        string $session = 'madeline-mcp',
        string|SqlDriver|RelationalStore|null $dsn = null,
        ?RelationalStore $store = null,
        ?SqlDriver $driver = null,
    ) {
        $this->sessionsDir = self::sessionDir();

        if ($dsn instanceof RelationalStore) {
            $store = $dsn;
            $dsn = null;
        } elseif ($dsn instanceof SqlDriver) {
            $driver = $dsn;
            $dsn = null;
        }

        if ($store !== null) {
            $this->store = $store;
        }

        if ($driver !== null) {
            $this->driver = $driver;
            (new Migrations($this->driver))->migrate();
            $this->store ??= new RelationalStore($this->driver);
        }

        if (is_string($dsn) && $dsn !== '') {
            $this->dsn = $dsn;
        } else {
            $envDsn = \getenv('MADELINE_DSN')
                ?: (\getenv('MADLINE_DSN')
                ?: (\getenv('DATABASE_URL')
                ?: ($_ENV['MADELINE_DSN'] ?? ($_ENV['MADLINE_DSN'] ?? ($_ENV['DATABASE_URL'] ?? null)))));
            if ($envDsn !== null && $envDsn !== '') {
                $this->dsn = (string) $envDsn;
            }
        }

        if ($this->dsn !== null && $this->store === null && $this->driver === null) {
            $this->driver = new PdoDriver(self::normalizePdoDsn($this->dsn));
            (new Migrations($this->driver))->migrate();
            $this->store = new RelationalStore($this->driver);
        }

        if ($this->store !== null) {
            $accounts = $this->store->listAccounts();
            if ($session === 'madeline-mcp' && !empty($accounts)) {
                $session = (string) $accounts[0]['id'];
            }
        } else {
            if (!\is_dir($this->sessionsDir)) {
                @\mkdir($this->sessionsDir, 0755, true);
            }

            // Auto-detect: if requested default session has no stored session,
            // fall back to the first existing one on disk.
            if ($session === 'madeline-mcp' && !\is_dir($this->sessionsDir . '/madeline-mcp')) {
                foreach (\scandir($this->sessionsDir) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..' && \is_dir($this->sessionsDir . '/' . $entry)) {
                        $session = $entry;
                        break;
                    }
                }
            }
        }

        $this->defaultSession = $session;

        // Backward-compatible environment variables for default session.
        if (!isset($this->configs[$this->defaultSession])) {
            $apiId = (int) (\getenv('API_ID') ?: \getenv('TELEGRAM_API_ID'));
            $apiHash = (string) (\getenv('API_HASH') ?: \getenv('TELEGRAM_API_HASH'));
            if ($apiId > 0 && $apiHash !== '') {
                $this->configs[$this->defaultSession] = [
                    'api_id' => $apiId,
                    'api_hash' => $apiHash,
                ];
            }
        }
    }

    public function isRelational(): bool
    {
        return $this->store !== null;
    }

    public function getRelationalStore(): ?RelationalStore
    {
        return $this->store;
    }

    public function getStore(): ?RelationalStore
    {
        return $this->store;
    }

    public function getDriver(): ?SqlDriver
    {
        return $this->driver;
    }

    /**
     * Normalize URL schemes to PDO-compatible DSNs.
     */
    public static function normalizePdoDsn(string $dsn): string
    {
        if (str_starts_with($dsn, 'postgres://') || str_starts_with($dsn, 'postgresql://')) {
            $parts = parse_url($dsn);
            $host = $parts['host'] ?? '127.0.0.1';
            $port = isset($parts['port']) ? (int) $parts['port'] : 5432;
            $db = isset($parts['path']) ? ltrim($parts['path'], '/') : 'madeline';
            $user = $parts['user'] ?? '';
            $pass = $parts['pass'] ?? '';
            $pdoDsn = "pgsql:host={$host};port={$port};dbname={$db}";
            if ($user !== '') {
                $pdoDsn .= ";user={$user}";
            }
            if ($pass !== '') {
                $pdoDsn .= ";password={$pass}";
            }
            return $pdoDsn;
        }
        return $dsn;
    }

    /**
     * Create appropriate database settings instance for MadelineProto.
     */
    public function createDatabaseSettings(string $sessionName): DatabaseAbstract
    {
        if ($this->dsn !== null && (
            str_starts_with($this->dsn, 'pgsql:')
            || str_starts_with($this->dsn, 'postgres://')
            || str_starts_with($this->dsn, 'postgresql://')
        )) {
            $normalized = self::normalizePdoDsn($this->dsn);
            $params = [];
            foreach (explode(';', substr($normalized, 6)) as $pair) {
                if (str_contains($pair, '=')) {
                    [$k, $v] = explode('=', $pair, 2);
                    $params[$k] = $v;
                }
            }
            $host = $params['host'] ?? '127.0.0.1';
            $port = isset($params['port']) ? (int) $params['port'] : 5432;
            $db = $params['dbname'] ?? ($params['database'] ?? 'madeline');
            $user = $params['user'] ?? ($params['username'] ?? 'root');
            $pass = $params['password'] ?? ($params['pass'] ?? '');

            $pgSettings = new Postgres();
            $pgSettings->setUri("tcp://{$host}:{$port}");
            $pgSettings->setDatabase($db);
            $pgSettings->setUsername($user);
            $pgSettings->setPassword($pass);
            $pgSettings->setEphemeralFilesystemPrefix($sessionName);
            return $pgSettings;
        }

        return new Memory();
    }

    /**
     * Register an account. If api_id / api_hash are omitted they are inherited.
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

        if ($this->store !== null) {
            $this->store->upsertAccount(-$apiId, $apiId, $apiHash, 'not_logged_in', null);
            return;
        }

        // Persist into the MadelineProto session database on disk.
        $this->buildApi($sessionName, $apiId, $apiHash);
    }

    /**
     * Resolve app api_id / api_hash, inheriting from database or disk session.
     */
    private function resolveAppCredentials(?int $apiId, ?string $apiHash): array
    {
        if ($apiId !== null && $apiHash !== null && $apiId > 0 && $apiHash !== '') {
            return [$apiId, $apiHash];
        }

        // 1) From RelationalStore if active
        if ($this->store !== null) {
            foreach ($this->store->listAccounts() as $row) {
                if (!empty($row['api_id']) && !empty($row['api_hash'])) {
                    return [(int) $row['api_id'], (string) $row['api_hash']];
                }
            }
        }

        // 2) Default (primary) session database on disk.
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

        // 3) Environment variables.
        $envId = (int) (\getenv('API_ID') ?: \getenv('TELEGRAM_API_ID'));
        $envHash = (string) (\getenv('API_HASH') ?: \getenv('TELEGRAM_API_HASH'));
        if ($envId > 0 && $envHash !== '') {
            return [$envId, $envHash];
        }

        // 4) Any existing session database (they all share the same app key).
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
     * Enumerate accounts from RelationalStore or disk session databases.
     */
    public function listAccounts(): array
    {
        if ($this->store !== null) {
            $accounts = [];
            $rows = $this->store->listAccounts();
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $apiId = (int) $row['api_id'];
                $authState = $row['auth_state'] ?? null;
                $isLoggedIn = ($id > 0) && ($authState === 'authorized' || $authState === 'LOGGED_IN' || $authState === '3');

                $username = null;
                if ($id > 0) {
                    $user = $this->store->getUser($id);
                    if ($user !== null) {
                        $username = $user['username'] ?? null;
                    }
                }

                $sessionName = (string) $id;
                if ($this->defaultSession !== '' && $this->defaultSession !== 'madeline-mcp' && (count($rows) === 1 || (string)$id === (string)$this->defaultSession)) {
                    $sessionName = $this->defaultSession;
                }

                $stateLabel = match ($authState) {
                    'authorized', 'LOGGED_IN', '3' => 'LOGGED_IN',
                    'WAITING_CODE', '1' => 'WAITING_CODE',
                    'WAITING_PASSWORD', '2' => 'WAITING_PASSWORD',
                    'WAITING_SIGNUP', '5' => 'WAITING_SIGNUP',
                    'LOGGED_OUT', '4' => 'LOGGED_OUT',
                    default => ($isLoggedIn ? 'LOGGED_IN' : ($authState ?: 'NOT_LOGGED_IN')),
                };

                $accounts[] = [
                    'session_name' => $sessionName,
                    'id' => $id,
                    'api_id' => $apiId,
                    'state' => $stateLabel,
                    'logged_in' => $isLoggedIn,
                    'username' => $username,
                ];
            }
            return $accounts;
        }

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

    /**
     * Get login state for a session.
     */
    public function getLoginState(?string $sessionName = null): array
    {
        $sessionName ??= $this->defaultSession;
        if ($this->store !== null) {
            $account = $this->findStoreAccount($sessionName);
            if ($account === null && !isset($this->configs[$sessionName])) {
                throw new RuntimeException(
                    "Account for session '$sessionName' is not configured. Use add_account first."
                );
            }
            if ($account !== null) {
                $id = (int) $account['id'];
                $authState = $account['auth_state'] ?? null;
                $isLoggedIn = ($id > 0) && ($authState === 'authorized' || $authState === 'LOGGED_IN' || $authState === '3');
                $state = match ($authState) {
                    'authorized', 'LOGGED_IN', '3' => 'LOGGED_IN',
                    'WAITING_CODE', '1' => 'WAITING_CODE',
                    'WAITING_PASSWORD', '2' => 'WAITING_PASSWORD',
                    'WAITING_SIGNUP', '5' => 'WAITING_SIGNUP',
                    'LOGGED_OUT', '4' => 'LOGGED_OUT',
                    default => ($isLoggedIn ? 'LOGGED_IN' : ($authState ?: 'NOT_LOGGED_IN')),
                };
                return [
                    'state' => $state,
                    'logged_in' => $isLoggedIn,
                ];
            }
        }

        $api = $this->api($sessionName);
        $auth = $api->getAuthorization();
        $me = $api->getSelf();
        $loggedIn = \is_array($me) && $auth === API::LOGGED_IN;
        return [
            'state' => match ($auth) {
                API::LOGGED_IN => 'LOGGED_IN',
                API::WAITING_CODE => 'WAITING_CODE',
                API::WAITING_PASSWORD => 'WAITING_PASSWORD',
                API::WAITING_SIGNUP => 'WAITING_SIGNUP',
                API::LOGGED_OUT => 'LOGGED_OUT',
                default => 'NOT_LOGGED_IN',
            },
            'logged_in' => $loggedIn,
        ];
    }

    /**
     * Get self/authenticated user info.
     */
    public function getMe(?string $sessionName = null): mixed
    {
        $sessionName ??= $this->defaultSession;
        if ($this->store !== null) {
            $account = $this->findStoreAccount($sessionName);
            if ($account === null && !isset($this->configs[$sessionName])) {
                throw new RuntimeException(
                    "Account for session '$sessionName' is not configured. Use add_account first."
                );
            }
            if ($account !== null) {
                $id = (int) $account['id'];
                if ($id <= 0) {
                    return false;
                }
                $user = $this->store->getUser($id);
                if ($user !== null) {
                    if (!empty($user['raw'])) {
                        $me = is_string($user['raw']) ? json_decode($user['raw'], true) : $user['raw'];
                        if (is_array($me)) {
                            if (!isset($me['id'])) {
                                $me['id'] = $id;
                            }
                            if (!isset($me['_'])) {
                                $me['_'] = 'user';
                            }
                            if (isset($user['username']) && !isset($me['username'])) {
                                $me['username'] = $user['username'];
                            }
                            if (isset($user['first_name']) && !isset($me['first_name'])) {
                                $me['first_name'] = $user['first_name'];
                            }
                            return $me;
                        }
                    }
                    return [
                        '_' => 'user',
                        'id' => $id,
                        'first_name' => $user['first_name'] ?? 'User ' . $id,
                        'last_name' => $user['last_name'] ?? null,
                        'username' => $user['username'] ?? null,
                        'phone' => $user['phone'] ?? null,
                        'status' => ['_' => 'userStatusOnline'],
                    ];
                }
                return [
                    '_' => 'user',
                    'id' => $id,
                    'first_name' => 'User ' . $id,
                    'status' => ['_' => 'userStatusOnline'],
                ];
            }
        }

        return $this->api($sessionName)->getSelf();
    }

    /**
     * Resolve a peer username or phone from RelationalStore.
     */
    public function resolvePeer(string $usernameOrPhone): ?array
    {
        if ($this->store === null) {
            return null;
        }

        $clean = ltrim($usernameOrPhone, '@');
        $resolved = $this->store->resolvePeer($clean);
        if ($resolved === null) {
            $resolved = $this->store->resolvePeer($usernameOrPhone);
        }

        if ($resolved === null) {
            return null;
        }

        $peerId = (int) $resolved['peer_id'];
        $type = $resolved['type'] ?? 'user';

        if ($type === 'user') {
            $user = $this->store->getUser($peerId);
            if ($user !== null && !empty($user['raw'])) {
                $raw = is_string($user['raw']) ? json_decode($user['raw'], true) : $user['raw'];
                if (is_array($raw)) {
                    return ['User' => $raw, 'type' => 'user', 'bot_api_id' => $peerId];
                }
            }
            return [
                'User' => [
                    '_' => 'user',
                    'id' => $peerId,
                    'username' => $user['username'] ?? $clean,
                    'first_name' => $user['first_name'] ?? 'User ' . $peerId,
                ],
                'type' => 'user',
                'bot_api_id' => $peerId,
            ];
        }

        if ($type === 'chat') {
            $chat = $this->store->getChat($peerId);
            return [
                'Chat' => $chat['raw'] ?? ['_' => 'chat', 'id' => $peerId, 'title' => $chat['title'] ?? 'Chat ' . $peerId],
                'type' => 'chat',
                'bot_api_id' => -$peerId,
            ];
        }

        if ($type === 'channel') {
            $channel = $this->store->getChannel($peerId);
            return [
                'Channel' => $channel['raw'] ?? ['_' => 'channel', 'id' => $peerId, 'title' => $channel['title'] ?? 'Channel ' . $peerId],
                'type' => 'channel',
                'bot_api_id' => -(1000000000000 + $peerId),
            ];
        }

        return null;
    }

    public function findStoreAccount(string $sessionName): ?array
    {
        if ($this->store === null) {
            return null;
        }

        if (is_numeric($sessionName)) {
            $account = $this->store->getAccount((int) $sessionName);
            if ($account !== null) {
                return $account;
            }
        }

        $rows = $this->store->listAccounts();
        if (count($rows) === 1) {
            return $rows[0];
        }

        foreach ($rows as $row) {
            if ((string) $row['id'] === $sessionName) {
                return $row;
            }
            if ($row['id'] > 0) {
                $user = $this->store->getUser((int) $row['id']);
                if ($user !== null && ($user['username'] ?? null) === $sessionName) {
                    return $row;
                }
            }
        }

        if ($sessionName === $this->defaultSession && !empty($rows)) {
            return $rows[0];
        }

        return null;
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

        if ($this->store !== null) {
            $account = $this->findStoreAccount($sessionName);
            if ($account !== null && !empty($account['api_id']) && !empty($account['api_hash'])) {
                return $this->buildDatabaseApi($sessionName, (int) $account['api_id'], (string) $account['api_hash']);
            }
            if (isset($this->configs[$sessionName])) {
                $cfg = $this->configs[$sessionName];
                return $this->buildDatabaseApi($sessionName, (int) $cfg['api_id'], (string) $cfg['api_hash']);
            }
            throw new RuntimeException(
                "Account for session '$sessionName' is not configured. Use add_account first."
            );
        }

        $path = $this->sessionsDir . '/' . $sessionName;
        if (\is_dir($path)) {
            // Session database exists: load api keys from it.
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

    private function buildDatabaseApi(string $sessionName, int $apiId, string $apiHash): API
    {
        $appInfo = (new AppInfo())
            ->setApiId($apiId)
            ->setApiHash($apiHash);

        $settings = (new Settings())->setAppInfo($appInfo);

        $logLevel = (string) \getenv('LOG_LEVEL');
        if ($logLevel !== '') {
            $settings->setLogger((new Logger())->setLevel($logLevel));
        }

        $dbSettings = $this->createDatabaseSettings($sessionName);
        $settings->setDb($dbSettings);

        $sessionPath = sys_get_temp_dir() . '/madeline_mcp_' . md5($sessionName);
        $api = new API($sessionPath, $settings);
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
            if (\method_exists($api, 'methodCallAsyncRead')) {
                return $api->methodCallAsyncRead($method, $args)->await();
            }
            return $api->__call('methodCallAsyncRead', [$method, $args])->await();
        }
        [$ns, $fn] = \explode('.', $method, 2);
        return $api->{$ns}->{$fn}($args);
    }
}
