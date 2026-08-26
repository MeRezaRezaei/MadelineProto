<?php declare(strict_types=1);

namespace danog\MadelineProto\Sync;

/**
 * Default no-op account data provider.
 *
 * The production daemon is expected to supply an MTProto-backed provider that
 * pulls real Telegram data; this placeholder lets the daemon boot and run its
 * sync loop (which stays idle until a real provider is wired in).
 */
final class NullAccountDataProvider implements AccountDataProvider
{
    public function pull(int $accountId): array
    {
        return [];
    }
}
