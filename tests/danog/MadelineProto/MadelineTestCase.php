<?php

declare(strict_types=1);

namespace danog\MadelineProto\Test;

use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Tools;
use PHPUnit\Framework\TestCase;

/** @internal */
abstract class MadelineTestCase extends TestCase
{
    /**
     * MadelineProto instance.
     */
    protected static ?API $MadelineProto = null;

    /**
     * Setup MadelineProto instance.
     */
    public static function setUpBeforeClass(): void
    {
        if (self::$MadelineProto !== null) {
            return;
        }
        $apiHash = getenv('API_HASH');
        $apiId = getenv('API_ID');
        if (!$apiHash || !$apiId) {
            self::markTestSkipped('API_ID and API_HASH environment variables are required.');
        }
        $settings = new Settings;
        $settings->getAppInfo()->setApiId((int) $apiId)->setApiHash((string) $apiHash);
        $settings->getLogger()->setType(Logger::FILE_LOGGER)->setExtra(__DIR__.'/../../MadelineProto.log')->setLevel(Logger::ULTRA_VERBOSE);
        self::$MadelineProto = new API(
            'testing.madeline',
            $settings
        );
        $unlock = Tools::flock(sys_get_temp_dir().'/login.flock', LOCK_EX);
        self::$MadelineProto->botLogin((string) getenv('BOT_TOKEN'));
        $unlock();
    }

    /**
     * Teardown.
     */
    public static function tearDownAfterClass(): void
    {
        self::$MadelineProto = null;
        while (gc_collect_cycles());
    }
}
