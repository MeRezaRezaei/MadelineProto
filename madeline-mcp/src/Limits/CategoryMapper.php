<?php

declare(strict_types=1);

namespace MadelineMcp\Limits;

/**
 * Maps an MCP tool or Telegram dotted method onto a budget category that we
 * track against community-documented limits (limits.tginfo.me).
 *
 * Categories are small on purpose: only things that get an AI into trouble.
 */
final class CategoryMapper
{
    /** tool/method pattern => [category, tginfo limit id] */
    private const MAP = [
        // Username resolves: 200/day (search.username_resolve_limit)
        'resolve' => [
            'limit_id' => 'search.username_resolve_limit',
            'patterns' => ['/^resolve_peer$/', '/^(accounts|contacts|users)\.resolveUsername$/'],
        ],
        // Chat/channel creation: ~50/day (accounts.groups_and_channels_creation)
        'creation' => [
            'limit_id' => 'accounts.groups_and_channels_creation',
            'patterns' => ['/\.?create(Channel|Chat|Supergroup)$/'],
        ],
        // Outgoing messages rate (per-chat ~1/s bursts; groups 20/min for bots)
        'message' => [
            'limit_id' => null,
            // Driving other bots still spends our account's message quota.
            'patterns' => ['/^send_(message|media)$/', '/^bot\.invoke$/', '/^messages\.(sendMessage|sendMedia|sendMultiMedia|sendInlineBotResult)$/'],
        ],
        // Membership changes (stateful caps: 500 free / 1000 premium)
        'membership' => [
            'limit_id' => 'accounts.channels_and_chats_number',
            'patterns' => ['/(joinChannel|importChatInvite|leaveChannel|deleteChannel|DeleteUserHistory?)$/'],
        ],
        // Folders (10/30, chats per folder 100/200)
        'folders' => [
            'limit_id' => 'folders.folder_amount',
            'patterns' => ['/^folders\./'],
        ],
    ];

    /** @return array{category:string, limit_id:?string}|null */
    public static function map(string $tool): ?array
    {
        foreach (self::MAP as $category => $def) {
            foreach ($def['patterns'] as $re) {
                if (\preg_match($re, $tool) === 1) {
                    return ['category' => $category, 'limit_id' => $def['limit_id']];
                }
            }
        }
        return null;
    }
}
