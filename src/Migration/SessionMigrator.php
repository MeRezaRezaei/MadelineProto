<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Migration;

use danog\MadelineProto\API;
use danog\MadelineProto\APIWrapper;
use danog\MadelineProto\Accounts\AccountManager;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Db\SqlDriver;
use danog\MadelineProto\MTProto;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Migrates legacy flat-file MadelineProto sessions to PostgreSQL / SQLite relational store.
 */
class SessionMigrator
{
    private const PHP_HEADER = '<?php __HALT_COMPILER();';

    private SqlDriver $driver;
    private RelationalStore $store;
    private AccountManager $accounts;
    private string $baseDir;

    public function __construct(
        string|SqlDriver $dsnOrDriver,
        ?RelationalStore $store = null,
        ?string $baseDir = null,
        ?AccountManager $accounts = null
    ) {
        if (is_string($dsnOrDriver)) {
            $this->driver = new PdoDriver($dsnOrDriver);
        } else {
            $this->driver = $dsnOrDriver;
        }

        (new Migrations($this->driver))->migrate();

        $this->store = $store ?? new RelationalStore($this->driver);
        $this->accounts = $accounts ?? new AccountManager($this->store);
        $this->baseDir = $baseDir ?? (getcwd() ?: '.') . '/sessions';
    }

    /**
     * Migrate a flat session directory or file into relational database.
     *
     * @param string      $sessionPathOrName Path to session directory/file, or session name
     * @param bool        $cleanup           Whether to delete old session files after migration
     * @param string|null $archiveDir        Optional directory to archive files to before deletion
     * @return array{
     *     success: bool,
     *     user_id: int,
     *     api_id: int,
     *     api_hash: string,
     *     auth_state: ?string,
     *     cleaned_up: bool
     * }
     */
    public function migrate(
        string $sessionPathOrName,
        bool $cleanup = false,
        ?string $archiveDir = null
    ): array {
        $resolved = $this->resolveSessionLocation($sessionPathOrName);
        $data = $this->extractSessionData($resolved['session_file']);

        $userId = $data['user_id'];
        $apiId = $data['api_id'];
        $apiHash = $data['api_hash'];
        $authState = $data['auth_state'];
        $sessionBlob = $data['session_blob'];
        $user = $data['user'];

        // 1. Upsert account record
        $this->store->upsertAccount(
            $userId,
            $apiId,
            $apiHash,
            $authState,
            $sessionBlob
        );

        // 2. If user_id is positive (logged-in account), link self and upsert user record
        if ($userId > 0) {
            $this->store->linkAccountEntity($userId, $userId, 'self');

            $userRow = [
                'user_id' => $userId,
                'access_hash' => $user['access_hash'] ?? null,
                'username' => $user['username'] ?? null,
                'phone' => $user['phone'] ?? null,
                'first_name' => $user['first_name'] ?? null,
                'last_name' => $user['last_name'] ?? null,
                'photo' => isset($user['photo'])
                    ? (is_string($user['photo']) ? $user['photo'] : json_encode($user['photo']))
                    : null,
                'bot' => !empty($user['bot']) ? 1 : 0,
                'status' => isset($user['status'])
                    ? (is_string($user['status']) ? $user['status'] : json_encode($user['status']))
                    : null,
                'raw' => isset($user['raw'])
                    ? (is_string($user['raw']) ? $user['raw'] : json_encode($user['raw']))
                    : json_encode($user ?? ['user_id' => $userId]),
            ];

            $this->store->upsertUser($userRow);
        }

        $cleanedUp = false;
        if ($cleanup) {
            $this->cleanupSessionFiles($resolved['target_path'], $archiveDir);
            $cleanedUp = true;
        }

        return [
            'success' => true,
            'user_id' => $userId,
            'api_id' => $apiId,
            'api_hash' => $apiHash,
            'auth_state' => $authState,
            'cleaned_up' => $cleanedUp,
        ];
    }

    /**
     * Resolve session directory or safe.php path.
     *
     * @return array{target_path: string, session_file: string}
     */
    private function resolveSessionLocation(string $sessionPathOrName): array
    {
        $candidates = [
            $sessionPathOrName,
            rtrim($this->baseDir, '/') . '/' . $sessionPathOrName,
            'sessions/' . $sessionPathOrName,
            $sessionPathOrName . '.safe.php',
            rtrim($this->baseDir, '/') . '/' . $sessionPathOrName . '.safe.php',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                $safePhp = $candidate . '/safe.php';
                if (file_exists($safePhp)) {
                    return ['target_path' => $candidate, 'session_file' => $safePhp];
                }
                // Check if any other .php session file is present
                foreach (scandir($candidate) ?: [] as $file) {
                    if (str_ends_with($file, '.php') && !in_array($file, ['lightState.php', 'ipcState.php'], true)) {
                        return ['target_path' => $candidate, 'session_file' => $candidate . '/' . $file];
                    }
                }
                return ['target_path' => $candidate, 'session_file' => $safePhp];
            }

            if (is_file($candidate)) {
                return ['target_path' => $candidate, 'session_file' => $candidate];
            }
        }

        throw new InvalidArgumentException("Session path or name '{$sessionPathOrName}' does not exist.");
    }

    /**
     * Extract session metadata from safe.php or serialized file.
     *
     * @return array{
     *     api_id: int,
     *     api_hash: string,
     *     user_id: int,
     *     auth_state: ?string,
     *     session_blob: ?string,
     *     user: ?array
     * }
     */
    public function extractSessionData(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Session file '{$filePath}' does not exist.");
        }

        $rawContent = file_get_contents($filePath);
        if ($rawContent === false || $rawContent === '') {
            throw new RuntimeException("Session file '{$filePath}' is empty or unreadable.");
        }

        $unserialized = $this->unserializePayload($rawContent);

        $apiId = null;
        $apiHash = null;
        $userId = null;
        $authState = null;
        $userData = null;
        $sessionBlob = $rawContent;

        if ($unserialized instanceof APIWrapper) {
            $api = $unserialized->getAPI();
            if ($api instanceof MTProto) {
                try {
                    $appInfo = $api->settings->getAppInfo();
                    $apiId = $appInfo->getApiId();
                    $apiHash = $appInfo->getApiHash();
                } catch (Throwable) {
                }

                try {
                    $self = $api->getSelf();
                    if (is_array($self)) {
                        $userData = $self;
                        $userId = (int) ($self['id'] ?? $self['user_id'] ?? 0);
                    }
                } catch (Throwable) {
                }

                try {
                    $authCode = $api->getAuthorization();
                    $authState = ($authCode === API::LOGGED_IN || $userData !== null) ? 'authorized' : 'not_logged_in';
                } catch (Throwable) {
                }
            }
        } elseif (is_array($unserialized)) {
            $apiId = $unserialized['api_id'] ?? $unserialized['app_info']['api_id'] ?? $unserialized['settings']['app_info']['api_id'] ?? null;
            $apiHash = $unserialized['api_hash'] ?? $unserialized['app_info']['api_hash'] ?? $unserialized['settings']['app_info']['api_hash'] ?? null;
            $userId = $unserialized['user_id'] ?? $unserialized['id'] ?? $unserialized['self']['id'] ?? $unserialized['user']['id'] ?? $unserialized['authorization']['user']['id'] ?? null;
            $authState = $unserialized['auth_state'] ?? $unserialized['authorization']['state'] ?? null;
            $userData = $unserialized['user'] ?? $unserialized['self'] ?? $unserialized['authorization']['user'] ?? null;
            if (isset($unserialized['session_blob'])) {
                $sessionBlob = $unserialized['session_blob'];
            }
        } elseif (is_object($unserialized)) {
            $reflection = new ReflectionClass($unserialized);
            foreach ($reflection->getProperties() as $prop) {
                $prop->setAccessible(true);
                $name = $prop->getName();
                $val = $prop->getValue($unserialized);
                if ($name === 'api_id' || $name === 'apiId') {
                    $apiId = (int) $val;
                } elseif ($name === 'api_hash' || $name === 'apiHash') {
                    $apiHash = (string) $val;
                } elseif ($name === 'user_id' || $name === 'userId' || $name === 'id') {
                    $userId = (int) $val;
                } elseif ($name === 'auth_state' || $name === 'authState') {
                    $authState = (string) $val;
                } elseif ($name === 'user' || $name === 'self') {
                    $userData = (array) $val;
                } elseif ($name === 'session_blob' || $name === 'sessionBlob') {
                    $sessionBlob = (string) $val;
                }
            }
        }

        // Fallbacks
        $apiId = $apiId !== null ? (int) $apiId : (int) (getenv('TELEGRAM_API_ID') ?: 0);
        $apiHash = $apiHash !== null ? (string) $apiHash : (getenv('TELEGRAM_API_HASH') ?: '');

        if ($userId === null && is_array($userData)) {
            $userId = isset($userData['user_id']) ? (int) $userData['user_id'] : (isset($userData['id']) ? (int) $userData['id'] : null);
        }

        if ($userId === null || $userId === 0) {
            if ($apiId > 0) {
                $userId = -$apiId;
            } else {
                throw new RuntimeException("Could not extract user_id or api_id from session file '{$filePath}'.");
            }
        }

        if ($apiId === 0) {
            throw new RuntimeException("Could not extract api_id from session file '{$filePath}'.");
        }

        if ($apiHash === '') {
            throw new RuntimeException("Could not extract api_hash from session file '{$filePath}'.");
        }

        if ($authState === null) {
            $authState = $userId > 0 ? 'authorized' : 'not_logged_in';
        }

        return [
            'api_id' => $apiId,
            'api_hash' => $apiHash,
            'user_id' => (int) $userId,
            'auth_state' => $authState,
            'session_blob' => $sessionBlob,
            'user' => $userData,
        ];
    }

    /**
     * Unserialize raw session payload with MadelineProto header handling.
     */
    private function unserializePayload(string $rawContent): mixed
    {
        if (str_starts_with($rawContent, self::PHP_HEADER)) {
            $headerLen = strlen(self::PHP_HEADER);
            $v = ord($rawContent[$headerLen]);
            $headerLen++;
            if ($v >= 2) {
                $headerLen += 2; // skip PHP major/minor
            }
            $igbinary = false;
            if ($v >= 3) {
                $igbinary = (bool) ord($rawContent[$headerLen]);
                $headerLen++;
            }
            $payload = substr($rawContent, $headerLen);

            if ($igbinary && function_exists('igbinary_unserialize')) {
                $res = @\igbinary_unserialize($payload);
                if ($res !== false) {
                    return $res;
                }
            }

            $res = @unserialize($payload);
            if ($res !== false) {
                return $res;
            }
        }

        // Try direct PHP unserialize
        $res = @unserialize($rawContent);
        if ($res !== false) {
            return $res;
        }

        // Try JSON decode
        $res = @json_decode($rawContent, true);
        if (is_array($res)) {
            return $res;
        }

        return null;
    }

    /**
     * Safely archive and remove flat session files.
     */
    public function cleanupSessionFiles(string $path, ?string $archiveDir = null): void
    {
        if (is_file($path)) {
            if ($archiveDir !== null) {
                if (!is_dir($archiveDir)) {
                    mkdir($archiveDir, 0777, true);
                }
                copy($path, $archiveDir . '/' . basename($path));
            }
            @unlink($path);
            if (file_exists($path . '.lock')) {
                @unlink($path . '.lock');
            }
            return;
        }

        if (is_dir($path)) {
            if ($archiveDir !== null && !is_dir($archiveDir)) {
                mkdir($archiveDir, 0777, true);
            }

            $files = scandir($path) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $filePath = $path . '/' . $file;
                if (is_file($filePath)) {
                    if ($archiveDir !== null) {
                        copy($filePath, $archiveDir . '/' . $file);
                    }
                    @unlink($filePath);
                }
            }

            // Remove session directory if empty
            @rmdir($path);
        }
    }
}
