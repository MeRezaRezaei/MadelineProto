<?php

declare(strict_types=1);

namespace MadelineMcp\Assistant;

use MadelineMcp\ApiClient;
use MadelineMcp\ToolCatalog;
use Throwable;

/**
 * "Madeline Assistant" background bot.
 *
 * Architecture (v1 - will be reworked when proper update handlers land):
 *  - The BOT account runs this poll loop in the background.
 *  - Only messages from the OWNER peer are processed; everything else is dropped
 *    (prevents strangers using your AI quota and ai-to-ai loops).
 *  - A suppress map (cache/assistant-suppress.json) records the owner<->bot peer
 *    pair so FUTURE default MadelineProto update handlers can drop these updates,
 *    leaving the conversation exclusively to this handler.
 *  - Incoming text goes through an OpenAI-compatible agent loop against OmniRoute
 *    with the full madeline-mcp toolset (via ToolBridge), then the final answer is
 *    sent back to the owner.
 */
final class AssistantBot
{
    public const BOT_NAME = 'Madeline Assistant';
    public const DEFAULT_USERNAME = 'madeline_501558149_bot'; // static per owner; override via env

    private const MAX_TOOL_ROUNDS = 8;
    private const HISTORY_KEEP = 12;
    private const POLL_SECONDS = 2;

    /** @var array<int,array> rolling conversation history (OpenAI message format) */
    private array $history = [];
    private int $lastIncomingId = 0;
    private string $stateFile;
    private string $suppressFile;

    public function __construct(
        private readonly ApiClient $api,
        private readonly OmniClient $omni,
        private readonly ToolBridge $bridge,
        private readonly ?\Closure $log = null,
    ) {
        $cacheDir = $this->api->cacheDir();
        if (!\is_dir($cacheDir)) {
            @\mkdir($cacheDir, 0777, true);
        }
        $this->stateFile = $cacheDir . '/assistant-state.json';
        $this->suppressFile = $cacheDir . '/assistant-suppress.json';
    }

    // ---------------------------------------------------------------- state

    /** Persisted runtime state: owner id, bot id, last seen msg. */
    public function loadState(): array
    {
        if (\is_file($this->stateFile)) {
            $d = \json_decode((string) \file_get_contents($this->stateFile), true);
            if (\is_array($d)) {
                return $d;
            }
        }
        throw new \RuntimeException('Assistant state missing. Run: bin/madeline-assistant ensure');
    }

    public function saveState(array $state): void
    {
        \file_put_contents($this->stateFile, \json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Record the owner<->bot pair that must be EXCLUDED from default update
     * handlers (loop prevention). Future handler framework reads this file.
     */
    public function writeSuppressMap(int $ownerId, int $botId): void
    {
        $pairs = self::readSuppressPairs($this->suppressFile);
        $pair = [$ownerId, $botId];
        if (!\in_array($pair, $pairs, true)) {
            $pairs[] = $pair;
        }
        \file_put_contents($this->suppressFile, \json_encode([
            'purpose' => 'Default update handlers MUST drop updates for these peer pairs; they are owned by the Madeline Assistant handler.',
            'pairs' => $pairs,
        ], JSON_PRETTY_PRINT));
    }

    /** @return list<array{0:int,1:int}> */
    public static function readSuppressPairs(string $file): array
    {
        if (!\is_file($file)) {
            return [];
        }
        $d = \json_decode((string) \file_get_contents($file), true);
        return \is_array($d['pairs'] ?? null) ? $d['pairs'] : [];
    }

    public function suppressFile(): string
    {
        return $this->suppressFile;
    }

    // ------------------------------------------------------------------ run

    public function run(): never
    {
        $st = $this->loadState();
        $ownerId = (int) $st['owner_id'];
        $botApi = $this->api->api((string) $st['session']);
        $this->lastIncomingId = (int) ($st['last_msg_id'] ?? 0);
        ($this->log)("Assistant online. owner={$ownerId} session={$st['session']}");

        while (true) {
            try {
                foreach ($this->fetchNewIncoming($botApi, $ownerId) as $msg) {
                    $reply = $this->handleText($ownerId, (string) $msg['message']);
                    $this->sendReply($botApi, $ownerId, $reply);
                }
                $st['last_msg_id'] = $this->lastIncomingId;
                $this->saveState($st);
            } catch (Throwable $e) {
                ($this->log ?? static fn(string $m) => null)('loop error: ' . $e->getMessage());
                \sleep(5);
            }
            \sleep(self::POLL_SECONDS);
        }
    }

    /** @return list<array> oldest-first incoming text messages from $peerId */
    private function fetchNewIncoming(object $botApi, int $peerId): array
    {
        $hist = $botApi->messages->getHistory(peer: $peerId, limit: 5);
        $msgs = $hist['messages'] ?? [];
        \usort($msgs, static fn($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        $out = [];
        foreach ($msgs as $m) {
            $id = (int) ($m['id'] ?? 0);
            $outMsg = (bool) ($m['out'] ?? false);           // out=true => OUR OWN bot reply -> drop (loop guard)
            $fromSelf = ((int) ($m['from_id']['user_id'] ?? 0)) === ((int) ($m['peer_id']['user_id'] ?? -1));
            $text = \trim((string) ($m['message'] ?? ''));
            if ($id <= $this->lastIncomingId || $outMsg || $text === '' || isset($m['action'])) {
                continue;
            }
            $this->lastIncomingId = $id;
            $out[] = $m;
        }
        return $out;
    }

    private function sendReply(object $botApi, int $peerId, string $text): void
    {
        foreach (self::chunkText($text, 4096) as $piece) {
            $botApi->messages->sendMessage(peer: $peerId, message: $piece);
        }
    }

    // --------------------------------------------------------------- agent

    private function systemPrompt(): string
    {
        $self = '';
        try {
            $me = $this->api->getSelf();
            $self = " Account owner: @{$me['username']} ({$me['first_name']}).";
        } catch (Throwable) {
        }
        return "You are Madeline Assistant, an autonomous agent operating the Telegram account of its owner through tools."
            . $self
            . " Rules: use the provided tools to actually perform requested actions on this account; prefer read-only calls first;"
            . " destructive or irreversible actions require the owner's explicit request in the current conversation;"
            . " if a tool result contains a _quota or cooldown_active error, wait instead of retrying immediately;"
            . " answer in the owner's language, concisely.";
    }

    /** Full agent turn for one owner message. Returns final text. */
    public function handleText(int $ownerId, string $text): string
    {
        $this->history[] = ['role' => 'user', 'content' => $text];
        $tools = $this->bridge->openaiTools();
        $messages = \array_merge([['role' => 'system', 'content' => $this->systemPrompt()]], $this->history);

        try {
            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                $resp = $this->omni->chat($messages, $tools);
                $messages[] = $resp;
                $calls = $resp['tool_calls'] ?? [];
                if (!\is_array($calls) || $calls === []) {
                    $final = (string) ($resp['content'] ?? '');
                    $this->pushHistory(['role' => 'user', 'content' => $text], ['role' => 'assistant', 'content' => $final]);
                    return $final !== '' ? $final : '(empty reply)';
                }
                foreach ($calls as $call) {
                    $fn = (array) ($call['function'] ?? []);
                    $name = ToolBridge::decode((string) ($fn['name'] ?? ''));
                    $args = \json_decode((string) ($fn['arguments'] ?? '{}'), true);
                    $res = $this->bridge->call($name, \is_array($args) ? $args : []);
                    ($this->log ?? static fn(string $m) => null)("tool {$name} -> " . \substr(\json_encode(\array_keys($res)), 0, 80));
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($call['id'] ?? ''),
                        'content' => \json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ];
                }
            }
            return '(stopped after too many tool rounds)';
        } catch (Throwable $e) {
            return "\u{26a0}\u{fe0f} Assistant error: " . $e->getMessage();
        }
    }

    private function pushHistory(array ...$turns): void
    {
        foreach ($turns as $t) {
            $this->history[] = $t;
        }
        if (\count($this->history) > self::HISTORY_KEEP) {
            $this->history = \array_slice($this->history, -self::HISTORY_KEEP);
        }
    }

    /** Split into <=$max char chunks on line boundaries when possible. @return list<string> */
    public static function chunkText(string $text, int $max = 4096): array
    {
        if (\mb_strlen($text) <= $max) {
            return [$text];
        }
        $out = [];
        foreach (\str_split($text, $max) as $chunk) { // str_split is byte-safe fallback
            $out[] = $chunk;
        }
        $merged = [];
        $buf = '';
        foreach ($out as $piece) {
            if (\mb_strlen($buf . $piece) > $max && $buf !== '') {
                $merged[] = $buf;
                $buf = $piece;
            } else {
                $buf .= $piece;
            }
        }
        if ($buf !== '') {
            $merged[] = $buf;
        }
        return $merged;
    }
}
