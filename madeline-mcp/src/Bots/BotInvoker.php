<?php

declare(strict_types=1);

namespace MadelineMcp\Bots;

use danog\MadelineProto\API;
use Throwable;

/**
 * Executes one bot interaction and waits for the bot's reaction:
 *  - command  : send "/cmd args"
 *  - reply    : send the button text (that's how reply keyboards work)
 *  - callback : press an inline button via messages.getBotCallbackAnswer
 */
final class BotInvoker
{
    /**
     * @return array{action:string, kind:string, sent:bool, response:string,
     *              buttons:array<string,array{type:string,data?:string,msg_id?:int,url?:string}>,
     *              reply_msg_id:int|null, callback_answer:array|null}
     */
    public static function invoke(API $api, string $peer, string $action, array $map, int $waitSeconds = 6): array
    {
        $kind = BotScanner::classifyAction($action, $map);
        $callbackAnswer = null;
        $sent = false;

        // Baseline BEFORE acting: callbacks often EDIT the last bot message
        // (pagination, menus) instead of sending a new one.
        try {
            $h = $api->messages->getHistory(peer: $peer, limit: 1, offset_id: 0);
            $baseline = self::topIncomingState($h);
        } catch (Throwable) {
            $baseline = null;
        }

        if ($kind === 'callback') {
            $data = BotScanner::callbackDataFor($action, $map);
            $msgId = BotScanner::msgIdFor($action, $map);
            if ($data === null || $msgId === 0) {
                return self::fail($action, $kind, 'Callback button not in cached map; run bot.scan first.');
            }
            try {
                $answer = $api->messages->getBotCallbackAnswer(game: false, peer: $peer, msg_id: $msgId, data: $data);
                $callbackAnswer = [
                    'message' => (string) ($answer['message'] ?? ''),
                    'alert' => ($answer['_'] ?? '') === 'messages.botCallbackAnswerAlert',
                ];
            } catch (Throwable $e) {
                return self::fail($action, $kind, 'Callback failed: ' . $e->getMessage());
            }
        } else {
            try {
                $api->messages->sendMessage(peer: $peer, message: $action);
                $sent = true;
            } catch (Throwable $e) {
                return self::fail($action, $kind, 'Send failed: ' . $e->getMessage());
            }
        }

        if ($callbackAnswer !== null && $callbackAnswer['message'] !== '') {
            // Pure alert/toast answer, no message follows.
            return self::ok($action, $kind, $sent, '', [], null, $callbackAnswer);
        }

        // Wait for a NEW incoming message OR an EDIT of the baseline one.
        $reply = null;
        $fullButtons = [];
        $deadline = \microtime(true) + \max(1, $waitSeconds);
        while (\microtime(true) < $deadline) {
            \sleep(1);
            try {
                $h = $api->messages->getHistory(peer: $peer, limit: 8, offset_id: 0);
            } catch (Throwable) {
                continue;
            }
            $cand = self::pickReply((array) ($h['messages'] ?? []), $baseline);
            if ($cand !== null) {
                $reply = $cand;
                break;
            }
        }

        $text = '';
        if ($reply !== null) {
            $text = \is_string($reply['message'] ?? null) ? $reply['message'] : '';
            $mini = BotScanner::fromHistory(['messages' => [$reply]]);
            $fullButtons = $mini['inline_buttons'];
        }
        if ($text === '' && $fullButtons === [] && $callbackAnswer === null && $reply === null && $kind === 'callback' && isset($callbackAnswer)) {
            // Callback acked without visible change; still report success of press.
            return self::ok($action, $kind, $sent, '', [], null, $callbackAnswer, $waitSeconds);
        }

        return self::ok(
            $action,
            $kind,
            $sent,
            \mb_substr($text, 0, 4000),
            $fullButtons,
            $reply === null ? null : (int) ($reply['id'] ?? 0),
            $callbackAnswer,
        );
    }

    /** Latest incoming message state used to detect edits. */
    private static function topIncomingState(array $history): ?array
    {
        foreach ((array) ($history['messages'] ?? []) as $m) {
            if (!\is_array($m) || ($m['out'] ?? false) !== false) {
                continue;
            }
            return [
                'id' => (int) ($m['id'] ?? 0),
                'edit_date' => (int) ($m['edit_date'] ?? 0),
                'text' => \is_string($m['message'] ?? null) ? $m['message'] : '',
            ];
        }
        return null;
    }

    /**
     * Pure: pick the bot's reaction from a fresh history slice.
     * New incoming message (id > baseline.id) OR edit of it
     * (same id, newer edit_date / different text).
     *
     * @param list<array> $messages newest-first history slice
     */
    public static function pickReply(array $messages, ?array $baseline): ?array
    {
        if ($baseline === null) {
            foreach ($messages as $m) {
                if (\is_array($m) && ($m['out'] ?? false) === false) {
                    return $m;
                }
            }
            return null;
        }
        foreach ($messages as $m) {
            if (!\is_array($m) || ($m['out'] ?? false) !== false) {
                continue;
            }
            $id = (int) ($m['id'] ?? 0);
            if ($id > $baseline['id']) {
                return $m;
            }
            if ($id === $baseline['id']) {
                $edited = (int) ($m['edit_date'] ?? 0) > $baseline['edit_date']
                    || ((\is_string($m['message'] ?? null) ? $m['message'] : '') !== $baseline['text']);
                if ($edited) {
                    return $m;
                }
            }
            return null; // newest incoming checked; nothing changed yet
        }
        return null;
    }

    private static function ok(string $action, string $kind, bool $sent, string $response, array $buttons, ?int $replyMsgId, ?array $cbAnswer): array
    {
        $out = [
            'action' => $action,
            'kind' => $kind,
            'sent' => $sent,
            'response' => $response,
            'buttons' => $buttons ?: new \stdClass(),
            'reply_msg_id' => $replyMsgId,
            'callback_answer' => $cbAnswer,
        ];
        return $out;
    }

    private static function fail(string $action, string $kind, string $why): array
    {
        return ['action' => $action, 'kind' => $kind, 'sent' => false, 'response' => '', 'error' => $why];
    }
}
