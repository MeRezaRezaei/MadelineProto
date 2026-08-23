<?php

declare(strict_types=1);

namespace MadelineMcp\Bots;

/**
 * Turns a bot conversation into a structured "interaction map":
 * commands seen, reply-keyboard rows, inline buttons (url/callback),
 * so generic tools can drive any bot without per-bot codegen.
 *
 * Pure parsing lives in static methods -> offline testable.
 */
final class BotScanner
{
    /**
     * @param array $history MadelineProto messages.getHistory result
     */
    public static function fromHistory(array $history, string $username = '', string $title = '', string $description = ''): array
    {
        $commands = [];
        $replyButtons = [];
        $inline = [];
        $lastIncoming = '';
        $lastOutgoing = '';
        $samples = [];

        foreach (($history['messages'] ?? []) as $m) {
            if (!\is_array($m)) {
                continue;
            }
            $text = \is_string($m['message'] ?? null) ? $m['message'] : '';

            foreach (($m['entities'] ?? []) as $e) {
                if (\is_array($e) && ($e['_'] ?? '') === 'messageEntityBotCommand') {
                    $cmd = \mb_substr($text, (int) ($e['offset'] ?? 0), (int) ($e['length'] ?? 0));
                    if (\preg_match('/^\/[A-Za-z0-9_]{1,32}$/', $cmd) === 1) {
                        $commands[$cmd] = true;
                    }
                }
            }
            if (($m['out'] ?? false) === true && \str_starts_with($text, '/')) {
                $commands[\preg_split('/\s+/', $text)[0]] = true;
                $lastOutgoing = $text;
            }

            $rm = $m['reply_markup'] ?? null;
            if (\is_array($rm)) {
                foreach ((array) ($rm['rows'] ?? []) as $row) {
                    foreach ((array) ($row['buttons'] ?? []) as $b) {
                        if (!\is_array($b)) {
                            continue;
                        }
                        $btnText = \is_string($b['text'] ?? null) ? $b['text'] : '';
                        if ($btnText === '') {
                            continue;
                        }
                        switch ($b['_'] ?? '') {
                            case 'keyboardButton':
                            case 'keyboardButtonRequestPhone':
                            case 'keyboardButtonRequestGeoLocation':
                                $replyButtons[$btnText] = true;
                                break;
                            case 'keyboardButtonUrl':
                                $inline[$btnText] = ['type' => 'url', 'url' => (string) ($b['url'] ?? ''), 'msg_id' => (int) ($m['id'] ?? 0)];
                                break;
                            case 'keyboardButtonCallback':
                                $inline[$btnText] = [
                                    'type' => 'callback',
                                    'data' => \base64_encode((string) ($b['data'] ?? '')),
                                    'msg_id' => (int) ($m['id'] ?? 0),
                                ];
                                break;
                            case 'keyboardButtonSwitchInline':
                                $inline[$btnText] = ['type' => 'switch_inline', 'query' => (string) ($b['query'] ?? ''), 'msg_id' => (int) ($m['id'] ?? 0)];
                                break;
                        }
                    }
                }
            }

            if (($m['out'] ?? false) === false && $text !== '') {
                if ($lastIncoming === '') {
                    $lastIncoming = $text;
                }
                if (\count($samples) < 5) {
                    $samples[] = \mb_substr($text, 0, 200);
                }
            }
        }

        return [
            'peer' => $username !== '' ? '@' . \ltrim($username, '@') : $title,
            'username' => $username,
            'title' => $title,
            'description' => $description,
            'commands' => \array_keys($commands),
            'reply_keyboard' => \array_chunk(\array_keys($replyButtons), 4),
            'inline_buttons' => $inline,
            'sample_replies' => $samples,
            'last_incoming' => \mb_substr($lastIncoming, 0, 400),
            'last_command_sent' => $lastOutgoing,
            'scanned_at' => \time(),
        ];
    }

    /** Resolve an action string against a map: '/cmd', inline-button text or reply-button text. */
    public static function classifyAction(string $action, array $map): string
    {
        if (\str_starts_with($action, '/')) {
            return 'command';
        }
        $b = $map['inline_buttons'][$action] ?? null;
        if (\is_array($b)) {
            return $b['type'] === 'callback' ? 'callback' : 'reply';
        }
        foreach (($map['reply_keyboard'] ?? []) as $row) {
            if (\in_array($action, (array) $row, true)) {
                return 'reply';
            }
        }
        // Unknown text: sending it is still how reply keyboards work.
        return 'reply';
    }

    public static function callbackDataFor(string $buttonText, array $map): ?string
    {
        $b = $map['inline_buttons'][$buttonText] ?? null;
        if (!\is_array($b) || ($b['type'] ?? '') !== 'callback') {
            return null;
        }
        $raw = \base64_decode((string) $b['data'], true);
        return $raw === false ? null : $raw;
    }

    public static function msgIdFor(string $buttonText, array $map): int
    {
        return (int) ($map['inline_buttons'][$buttonText]['msg_id'] ?? 0);
    }
}
