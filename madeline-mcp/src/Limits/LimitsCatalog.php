<?php

declare(strict_types=1);

namespace MadelineMcp\Limits;

use MadelineMcp\ApiClient;
use Throwable;

/**
 * MCP tool surface for limit awareness & budgeting. Local tools (not part of
 * the Telegram surface) so they live under the session.* prefix.
 */
final class LimitsCatalog
{
    /** @return list<array<string,mixed>> */
    public function tools(): array
    {
        $sn = ['session_name' => ['type' => 'string', 'description' => 'Optional account session to target (defaults to primary).']];
        return [
            [
                'name' => 'session.get_limits',
                'description' => 'Community-documented Telegram limits (limits.tginfo.me), auto-refreshed daily; free vs premium values included.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) ([
                    'category' => ['type' => 'string', 'description' => 'Optional category id filter (accounts, chats, messages, bots, folders, search, ...).'],
                    'refresh' => ['type' => 'boolean', 'description' => 'Force re-fetch from source instead of cache.'],
                ] + $sn)],
            ],
            [
                'name' => 'session.get_quota',
                'description' => 'Remaining budget for this account vs community limits: today\'s usage counters, message rates, active FLOOD_WAIT cooldowns, recent flood waits and cached @SpamBot standing.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) $sn],
            ],
            [
                'name' => 'session.check_spam_status',
                'description' => 'Probe @SpamBot for the account standing: ok / limited-until-date / banned. Cached ~1h unless force=true.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) ([
                    'force' => ['type' => 'boolean', 'description' => 'Bypass cache and re-probe @SpamBot now.'],
                ] + $sn)],
            ],
            [
                'name' => 'session.get_cooldowns',
                'description' => 'Active FLOOD_WAIT cooldown locks for this account; optionally clear them (clear=true) once the wait is genuinely over.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) ([
                    'clear' => ['type' => 'boolean', 'description' => 'Clear cooldown locks instead of listing.'],
                ] + $sn)],
            ],
        ];
    }

    public function has(string $tool): bool
    {
        return \in_array($tool, ['session.get_limits', 'session.get_quota', 'session.check_spam_status', 'session.get_cooldowns'], true);
    }

    public function dispatch(string $tool, array $args, ApiClient $client): mixed
    {
        try {
            $session = \is_string($args['session_name'] ?? null) && $args['session_name'] !== ''
                ? $args['session_name'] : $client->defaultSession();
            return match ($tool) {
                'session.get_limits' => $this->getLimits($args),
                'session.get_quota' => $this->getQuota($client, $session),
                'session.check_spam_status' => SpamBotChecker::check($client, $session, (bool) ($args['force'] ?? false)),
                'session.get_cooldowns' => $this->cooldowns($args, $session),
                default => ['_error' => true, 'message' => "Unknown limits tool: $tool"],
            };
        } catch (Throwable $e) {
            $sec = UsageTracker::floodSeconds($e);
            return [
                '_error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'class' => \get_class($e),
                'flood_wait_seconds' => $sec,
            ];
        }
    }

    private function getLimits(array $args): array
    {
        $repo = new LimitsRepository((string) (\getenv('LIMITS_LANG') ?: 'en'));
        return $repo->forTool((bool) ($args['refresh'] ?? false), isset($args['category']) ? (string) $args['category'] : null);
    }

    private function getQuota(ApiClient $client, string $session): array
    {
        $repo = new LimitsRepository((string) (\getenv('LIMITS_LANG') ?: 'en'));
        $tracker = UsageTracker::forSession($session);

        $premium = null;
        try {
            $api = $client->api($session);
            $me = $api->getSelf();
            if (\is_array($me) && \array_key_exists('premium', $me)) {
                $premium = (bool) $me['premium'];
            } elseif (\is_array($me) && isset($me['id'])) {
                // users.* namespace is guarded; use the high-level helper.
                $full = $api->getFullInfo($me['id']);
                $fu = (array) ($full['full_user'] ?? []);
                $premium = (bool) ($fu['premium'] ?? ($full['premium'] ?? false));
            }
        } catch (Throwable) {
            $premium = null;
        }

        // Numeric budgets we actively track, scaled by account type.
        $budgets = [];
        foreach ([
            'resolve_daily' => ['cat' => 'resolve', 'limit_id' => 'search.username_resolve_limit'],
            'creation_daily' => ['cat' => 'creation', 'limit_id' => 'accounts.groups_and_channels_creation'],
            'membership_cap' => ['cat' => 'membership', 'limit_id' => 'accounts.channels_and_chats_number'],
        ] as $label => $def) {
            $lim = $repo->numericLimit($def['limit_id'], (bool) ($premium ?? false));
            $used = $tracker->usedToday($def['cat']);
            $budgets[$label] = [
                'limit_id' => $def['limit_id'],
                'limit' => $lim['limit'],
                'text' => $lim['text'],
                'applies_premium_value' => $lim['premium'],
                'used_today' => $used,
                'remaining' => $lim['limit'] !== null ? \max(0, $lim['limit'] - $used) : null,
            ];
        }

        $spam = SpamBotChecker::cached($session);
        unset($spam['raw']);

        $blocked = null;
        foreach (['resolve', 'creation', 'message', 'membership', 'folders'] as $cat) {
            $b = $tracker->blocked($cat);
            if ($b !== null) {
                $blocked[$cat] = $b;
            }
        }

        return [
            'session' => $session,
            'premium_detected' => $premium,
            'budgets' => $budgets,
            'message_rate_last_minute' => $tracker->rate('message', 60),
            'message_rate_last_hour' => $tracker->rate('message', 3600),
            'active_cooldowns' => $blocked,
            'recent_flood_waits' => \array_slice($tracker->floodWaits(), -5),
            'spambot_standing' => $spam['status'] ?? 'not_checked_recently',
        ];
    }

    private function cooldowns(array $args, string $session): array
    {
        $tracker = UsageTracker::forSession($session);
        if ((bool) ($args['clear'] ?? false)) {
            $tracker->clearCooldowns(null);
            return ['cleared' => true];
        }
        return ['session' => $session, 'cooldowns' => $tracker->cooldowns()];
    }
}
