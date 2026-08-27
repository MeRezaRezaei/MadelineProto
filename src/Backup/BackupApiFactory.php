<?php declare(strict_types=1);

namespace danog\MadelineProto\Backup;

use danog\MadelineProto\API;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Database\Postgres;
use RuntimeException;

/**
 * Builds the MAIN account MadelineProto API instance from the RelationalStore,
 * mirroring madeline-mcp's ApiClient::buildDatabaseApi.
 */
final class BackupApiFactory
{
    public static function mainApi(RelationalStore $store, string $dsn): API
    {
        $acc = $store->listAccounts()[0] ?? null;
        if ($acc === null) {
            throw new RuntimeException('No account in store');
        }

        $app = (new AppInfo())
            ->setApiId((int) $acc['api_id'])
            ->setApiHash((string) $acc['api_hash']);

        $settings = (new Settings())->setAppInfo($app);

        $pg = new Postgres();
        $norm = (string) substr(PdoDriver::normalizeDsn($dsn), strlen('pgsql:'));
        foreach (explode(';', $norm) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            match ($k) {
                'host' => $pg->setUri('tcp://' . $v),
                'port' => $pg->setUri('tcp://' . parse_url($pg->getUri() ?? 'tcp://127.0.0.1', PHP_URL_HOST) . ':' . $v),
                'dbname' => $pg->setDatabase($v),
                'user' => $pg->setUsername($v),
                'password' => $pg->setPassword($v),
                default => null,
            };
        }
        $settings->setDb($pg);

        $sessionPath = sys_get_temp_dir() . '/madeline_backup_main_' . $acc['id'];
        if (!empty($acc['session_blob']) && !file_exists($sessionPath . '/safe.php')) {
            if (!is_dir($sessionPath)) {
                @mkdir($sessionPath, 0755, true);
            }
            file_put_contents($sessionPath . '/safe.php', $acc['session_blob']);
        }

        return new API($sessionPath, $settings);
    }
}
