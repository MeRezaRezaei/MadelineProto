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
     * @return array{action:string, sent:bool, response:string, new_inline_buttons:array, callback_answer:array|null, wait_seconds:int}
     */
    public static function invoke(API $api, string $peer, string $action, array $map, int $waitSeconds = 6): array
    {
        $kind = BotScanner::classifyAction($action, $map);
        $callbackAnswer = null;
        $sent = false;

        if ($kind === 'callback') {
            $data = BotScanner::callbackDataFor($action, $map);
            $msgId = BotScanner::msgIdFor($action, $map);
            if ($data === null || $msgId === 0) {
                return self::fail($kind, 'Callback button not in cached map; run bot.scan first.');
            }
            try {
                $answer = $api->messages->getBotCallbackAnswer(game: false, peer: $peer, msg_id: $msgId, data: $data);
                $callbackAnswer = [
                    'message' => (string) ($answer['message'] ?? ''),
                    'alert' => ($answer['_'] ?? '') === 'messages.botCallbackAnswerAlert',
                ];
            } catch (Throwable $e) {
                return self::fail($kind, 'Callback failed: ' . $e->getMessage());
            }
        } else {
            $payload = $kind === 'command' ? $action : $action; // reply buttons are triggered by sending the text
            try {
                $api->messages->sendMessage(peer: $peer, message: $payload);
                $sent = true;
            } catch (Throwable $e) {
                return self::fail($kind, 'Send failed: ' . $e->getMessage());
            }
        }

        // Wait for a NEW incoming message from the bot.
        $response = '';
        $newButtons = [];
        $deadline = \microtime(true) + \max(1, $waitSeconds);
        while (\microtime(true) < $deadline) {
            \sleep(1);
            try {
                $h = $api->messages->getHistory(peer: $peer, limit: 5, offset_id: 0);
            } catch (Throwable) {
                continue;
            }
            foreach ((array) ($h['messages'] ?? []) as $m) {
                if (!\is_array($m) || ($m['out'] ?? false) !== false) {
                    continue;
                }
                // Anything newer than our outgoing action counts.
                if (($m['date'] ?? 0) >= \time() - $waitSeconds - 2) {
                    $t = \is_string($m['message'] ?? null) ? $m['message'] : '';
                    if ($t !== '' && $response === '') {
                        $response = $t;
                    }
                    $rm = $m['reply_markup'] ?? null;
                    if (\is_array($rm)) {
                        $mini = BotScanner::fromHistory(['messages' => [$m]]);
                        foreach (($mini['inline_buttons'] ?? []) as $txt => $meta) {
                            unset($meta['msg_id']);
                            $newButtons[$txt] = $meta;
                        }
                        break;
                    }
                }
            }
            if ($response !== '' || $newButtons !== []) {
                break;
            }
        }

        return [
            'action' => $action,
            'kind' => $kind,
            'sent' => $sent,
            'response' => \mb_substr($response, 0, 4000),
            'new_inline_buttons' => $newButtons ?: new \stdClass(),
            'callback_answer' => $callbackAnswer,
            'wait_seconds' => $waitSeconds,
        ];
    }

    private static function fail(string $kind, string $why): array
    {
        return ['action' => $kind, 'kind' => $kind, 'sent' => false, 'response' => '', 'error' => $why];
    }
}
