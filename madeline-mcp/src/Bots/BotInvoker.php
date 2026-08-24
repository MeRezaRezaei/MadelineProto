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
     * Executes one bot interaction and collects EVERYTHING the bot did in
     * response (new messages + edits) until a quiet window closes:
     *  - command  : send "/cmd args"
     *  - reply    : send the button text (that's how reply keyboards work)
     *  - callback : press an inline button via messages.getBotCallbackAnswer
     *
     * Bots are async event components over a transcript: one input may produce
     * zero..N outputs in any shape (fresh messages, edits, alert-only callback
     * answers). The result therefore carries an ordered event diff.
     *
     * @return array{action:string, kind:string, sent:bool,
     *              events:list<array{id:int,type:string,text:string,buttons:array}>,
     *              response:string, buttons:array<string,array{type:string,data?:string,msg_id?:int,url?:string}>,
     *              reply_msg_id:int|null, callback_answer:array|null}|array{_error:true,message:string}
     */
    public static function invoke(API $api, string $peer, string $action, array $map, int $waitSeconds = 15, float $quietSeconds = self::QUIET_SECONDS): array
    {
        $kind = BotScanner::classifyAction($action, $map);
        $callbackAnswer = null;
        $sent = false;
        $before = self::captureTail($api, $peer);

        if ($kind === 'callback') {
            $data = BotScanner::callbackDataFor($action, $map);
            $msgId = BotScanner::msgIdFor($action, $map);
            if ($data === null || $msgId === 0) {
                return self::fail($action, $kind, 'stale_button: run bot.scan then retry');
            }
            if (!self::buttonStillLive(self::captureTail($api, $peer), $action, $data)) {
                return self::fail($action, $kind, 'stale_button: menu was re-rendered; run bot.scan then retry');
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
            if ($callbackAnswer['message'] !== '') {
                // Pure alert/toast answer, no message follows.
                return self::ok($action, $kind, $sent, '', [], [], null, $callbackAnswer);
            }
        } else {
            try {
                $api->messages->sendMessage(peer: $peer, message: $action);
                $sent = true;
            } catch (Throwable $e) {
                return self::fail($action, $kind, 'Send failed: ' . $e->getMessage());
            }
        }

        $collected = self::collectUntilQuiet(
            static fn() => self::captureTail($api, $peer),
            $before,
            (float) \max(1, $waitSeconds),
            $quietSeconds,
            self::POLL_INTERVAL,
        );
        $events = $collected['events'];
        $replyMsgId = null;
        foreach (\array_reverse($events) as $e) {
            if (($e['type'] ?? '') === 'new') {
                $replyMsgId = (int) $e['id'];
                break;
            }
        }
        return self::ok($action, $kind, $sent, $collected['response'], $events, $collected['buttons'], $replyMsgId, $callbackAnswer);
    }

    /**
     * Poll $fetch() until no NEW diffs for $quietSeconds or $maxWait elapsed.
     * $fetch returns raw TL messages of the transcript tail. Exactly ONE fetch
     * per iteration; on fresh events the baseline advances to that capture.
     *
     * @return array{events:list<array{id:int,type:string,text:string,buttons:array}>, response:string, buttons:array}
     */
    public static function collectUntilQuiet(callable $fetch, array $baselineMsgs, float $maxWait, float $quietSeconds = self::QUIET_SECONDS, int $pollInterval = self::POLL_INTERVAL): array
    {
        $seen = [];
        $events = [];
        $buttons = [];
        $deadline = \microtime(true) + \max(0.0, $maxWait);
        $lastDelta = \microtime(true);
        while (true) {
            if ($pollInterval > 0) {
                \sleep($pollInterval);
            }
            $nowMsgs = (array) $fetch();
            $ev = self::diffEvents($baselineMsgs, $nowMsgs);
            $fresh = [];
            foreach ($ev as $e) {
                $k = $e['id'] . ':' . \md5((string) $e['text']);
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $fresh[] = $e;
                }
            }
            if ($fresh !== []) {
                foreach ($fresh as $e) {
                    $events[] = $e;
                    foreach (($e['buttons'] ?? []) as $txt => $meta) {
                        $buttons[$txt] = $meta;
                    }
                }
                $baselineMsgs = $nowMsgs;
                $lastDelta = \microtime(true);
            } elseif (\microtime(true) - $lastDelta >= $quietSeconds || \microtime(true) >= $deadline) {
                break;
            }
            if (\microtime(true) >= $deadline) {
                break;
            }
        }
        $texts = \array_filter(\array_map(static fn($e) => (string) $e['text'], $events), static fn($t) => $t !== '');
        return ['events' => $events, 'response' => \trim(\implode("\n---\n", $texts)), 'buttons' => $buttons];
    }

    /** True if ANY live incoming message still carries this exact callback data under this text. */
    public static function buttonStillLive(array $liveMessages, string $text, string $b64Data): bool
    {
        foreach ($liveMessages as $m) {
            if (!\is_array($m) || ($m['out'] ?? false) !== false) {
                continue;
            }
            $b = BotScanner::buttonsOfMessage($m)[$text] ?? null;
            if (\is_array($b) && ($b['type'] ?? '') === 'callback' && ($b['data'] ?? '') === $b64Data) {
                return true;
            }
        }
        return false;
    }

    private static function captureTail(API $api, string $peer, int $limit = 10): array
    {
        try {
            return (array) ($api->messages->getHistory(peer: $peer, limit: $limit, offset_id: 0)['messages'] ?? []);
        } catch (Throwable) {
            return [];
        }
    }

    private static function ok(string $action, string $kind, bool $sent, string $response, array $events, array $buttons, ?int $replyMsgId, ?array $cbAnswer): array
    {
        $out = [
            'action' => $action,
            'kind' => $kind,
            'sent' => $sent,
            'events' => $events,
            'response' => $response,
            'buttons' => $buttons ?: new \stdClass(),
            'reply_msg_id' => $replyMsgId,
            'callback_answer' => $cbAnswer,
        ];
        return $out;
    }

    private static function fail(string $action, string $kind, string $why): array
    {
        return ['_error' => true, 'message' => $why, 'action' => $action, 'kind' => $kind, 'sent' => false, 'response' => '', 'events' => []];
    }

    public const QUIET_SECONDS = 2.0;
    public const POLL_INTERVAL = 1;

    /** @return list<array> incoming non-service messages */
    public static function incomingOnly(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if (\is_array($m) && ($m['_'] ?? 'message') === 'message' && ($m['out'] ?? false) === false) {
                $out[] = $m;
            }
        }
        return $out;
    }

    public static function fingerprint(array $m): string
    {
        // NOTE: MP error handler throws on warnings -> never (string)-cast a
        // possibly-non-scalar TL field directly.
        $msg = $m['message'] ?? '';
        return \md5(((int) ($m['edit_date'] ?? 0)) . '|' . (\is_string($msg) ? $msg : \json_encode($msg)));
    }

    /** @return array{max_in_id:int, prints:array<int,string>} */
    public static function snapshot(array $messages, int $depth = 5): array
    {
        $in = self::incomingOnly($messages);
        $maxIn = 0;
        $prints = [];
        foreach ($in as $m) {
            $id = (int) ($m['id'] ?? 0);
            $maxIn = \max($maxIn, $id);
            $prints[$id] = self::fingerprint($m);
        }
        return ['max_in_id' => $maxIn, 'prints' => \array_slice($prints, -$depth, null, true)];
    }

    /**
     * Ordered NEW/EDIT events between two transcript captures.
     *
     * @return list<array{id:int,type:string,text:string,buttons:array}>
     */
    public static function diffEvents(array $beforeMsgs, array $afterMsgs): array
    {
        $bIdx = [];
        foreach (self::incomingOnly($beforeMsgs) as $m) {
            $bIdx[(int) ($m['id'] ?? 0)] = $m;
        }
        $events = [];
        foreach (self::incomingOnly($afterMsgs) as $m) {
            $id = (int) ($m['id'] ?? 0);
            $fp = self::fingerprint($m);
            $was = isset($bIdx[$id]) ? self::fingerprint($bIdx[$id]) : null;
            if ($was === $fp) {
                continue;
            }
            $events[] = [
                'id' => $id,
                'type' => $was === null ? 'new' : 'edit',
                'text' => \is_string($m['message'] ?? null) ? $m['message'] : '',
                'buttons' => BotScanner::buttonsOfMessage($m),
            ];
        }
        \usort($events, static fn($a, $b) => $a['id'] <=> $b['id']);
        return $events;
    }
}
