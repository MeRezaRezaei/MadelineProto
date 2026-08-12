<?php

declare(strict_types=1);

namespace MadelineMcp;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger;
use danog\MadelineProto\TL\TL;
use Throwable;

/**
 * Builds and dispatches MCP tools.
 *
 * Exposes a curated set of ergonomic high-level tools plus a raw layer
 * ("list_methods" / "call_method") that reaches every Telegram bot & user method.
 */
final class ToolCatalog
{
    private ?TL $tl = null;

    public function __construct(
        private readonly ApiClient $client,
    ) {
    }

    private function tl(): TL
    {
        return $this->tl ??= $this->client->api()->getTL();
    }

    /** JSON Schema for a plain object argument bag. */
    private static function objectSchema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => (object) $properties,
            ...($required !== [] ? ['required' => $required] : []),
        ];
    }

    /** Schema helpers for common scalar kinds. */
    private static function str(string $desc): array
    {
        return ['type' => 'string', 'description' => $desc];
    }

    private static function int(string $desc): array
    {
        return ['type' => 'integer', 'description' => $desc];
    }

    private static function bool(string $desc): array
    {
        return ['type' => 'boolean', 'description' => $desc];
    }

    /** The full list of tools advertised to the client. */
    public function all(): array
    {
        return [
            [
                'name' => 'get_login_state',
                'description' => 'Return the current login state and identity.',
                'inputSchema' => self::objectSchema([]),
            ],
            [
                'name' => 'get_me',
                'description' => 'Return the logged-in account (user or bot) information.',
                'inputSchema' => self::objectSchema([]),
            ],
            [
                'name' => 'list_dialogs',
                'description' => 'List recent chats/dialogs (gives peer IDs usable in send_message).',
                'inputSchema' => self::objectSchema([
                    'limit' => self::int('Max number of dialogs (default 20).'),
                ]),
            ],
            [
                'name' => 'send_message',
                'description' => 'Send a text message to a peer (user, group, channel, username, or @username).',
                'inputSchema' => self::objectSchema([
                    'peer' => self::str('Target peer: id, username, @username, or phone.'),
                    'message' => self::str('Text of the message.'),
                    'parse_mode' => self::str('Optional parse mode: Markdown or HTML.'),
                    'reply_to' => self::int('Optional message id to reply to.'),
                ], ['peer', 'message']),
            ],
            [
                'name' => 'read_history',
                'description' => 'Read recent messages of a chat.',
                'inputSchema' => self::objectSchema([
                    'peer' => self::str('Chat: id, username, or @username.'),
                    'limit' => self::int('Max messages (default 30).'),
                ], ['peer']),
            ],
            [
                'name' => 'resolve_peer',
                'description' => 'Resolve a peer (id / username / @username / phone) into its full info.',
                'inputSchema' => self::objectSchema([
                    'peer' => self::str('Peer to resolve.'),
                ], ['peer']),
            ],
            [
                'name' => 'search_messages',
                'description' => 'Search messages inside a chat.',
                'inputSchema' => self::objectSchema([
                    'peer' => self::str('Chat to search in.'),
                    'query' => self::str('Search text.'),
                    'limit' => self::int('Max results (default 30).'),
                ], ['peer']),
            ],
            [
                'name' => 'get_full_chat_info',
                'description' => 'Get detailed metadata about a chat (participants count, photo, etc.).',
                'inputSchema' => self::objectSchema([
                    'peer' => self::str('Chat: id, username, or @username.'),
                ], ['peer']),
            ],
            [
                'name' => 'list_methods',
                'description' => 'List every callable Telegram method with its parameter shape (bot and user methods). Optional namespace filter (e.g. "messages", "users", "channels", "account").',
                'inputSchema' => self::objectSchema([
                    'namespace' => self::str('Optional namespace prefix filter.'),
                ]),
            ],
            [
                'name' => 'call_method',
                'description' => 'Call ANY Telegram method by dotted name, e.g. messages.sendMessage, users.getFullUser, account.updateProfile, channels.getChannels. Pass arguments as an object. Use list_methods to discover names and params.',
                'inputSchema' => self::objectSchema([
                    'method' => self::str('Dotted method name, e.g. messages.sendMessage.'),
                    'args' => [
                        'type' => 'object',
                        'description' => 'Method arguments keyed by parameter name.',
                    ],
                ], ['method']),
            ],
        ];
    }

    public function call(string $name, array $args): mixed
    {
        return match ($name) {
            'get_login_state' => $this->getLoginState(),
            'get_me' => $this->getMe(),
            'list_dialogs' => $this->listDialogs($args),
            'send_message' => $this->sendMessage($args),
            'read_history' => $this->readHistory($args),
            'resolve_peer' => $this->resolvePeer($args),
            'search_messages' => $this->searchMessages($args),
            'get_full_chat_info' => $this->getFullChatInfo($args),
            'list_methods' => $this->listMethods($args),
            'call_method' => $this->callRaw($args),
            default => ['_error' => true, 'message' => "Unknown tool: $name"],
        };
    }

    private function api(): API
    {
        return $this->client->api();
    }

    private function getLoginState(): mixed
    {
        return $this->twrap(function (): array {
            $state = $this->api()->getAuthorizationState();
            return [
                'state' => $state,
                'logged_in' => $state === API::LOGGED_IN,
            ];
        });
    }

    private function getMe(): mixed
    {
        return $this->twrap(fn () => $this->api()->getSelf());
    }

    private function resolvePeer(array $args): mixed
    {
        return $this->twrap(fn () => $this->api()->getInfo($args['peer']));
    }

    private function reportId(mixed $info): mixed
    {
        if (\is_array($info) && isset($info['id'])) {
            return $info['id'];
        }
        return $info;
    }

    private function listDialogs(array $args): mixed
    {
        $limit = \min((int) ($args['limit'] ?? 20), 200);
        return $this->twrap(fn () => $this->api()->getDialogIds(0, $limit));
    }

    private function sendMessage(array $args): mixed
    {
        $arg = [
            'peer' => $args['peer'],
            'message' => $args['message'],
        ];
        if (!empty($args['parse_mode'])) {
            $arg['parse_mode'] = $args['parse_mode'];
        }
        if (isset($args['reply_to'])) {
            $arg['reply_to_msg_id'] = $args['reply_to'];
        }
        return $this->twrap(fn () => $this->api()->messages->sendMessage($arg));
    }

    private function readHistory(array $args): mixed
    {
        $limit = \min((int) ($args['limit'] ?? 30), 200);
        return $this->twrap(fn () => $this->api()->messages->getHistory([
            'peer' => $args['peer'],
            'limit' => $limit,
        ]));
    }

    private function searchMessages(array $args): mixed
    {
        $limit = \min((int) ($args['limit'] ?? 30), 200);
        return $this->twrap(fn () => $this->api()->messages->search([
            'peer' => $args['peer'],
            'q' => $args['query'],
            'limit' => $limit,
        ]));
    }

    private function getFullChatInfo(array $args): mixed
    {
        return $this->twrap(fn () => $this->api()->getPwrChat($args['peer']));
    }

    /** Raw layer: list the entire TL method catalog. */
    private function listMethods(array $args): array
    {
        $ns = $args['namespace'] ?? null;
        $byMethod = $this->tl()->getMethods()->by_method ?? [];
        $out = [];
        foreach ($byMethod as $method => $def) {
            if ($ns !== null && \str_starts_with($method, $ns.'.')) {
                $out[$method] = $this->methodShape($def);
            } elseif ($ns === null) {
                $out[$method] = $this->methodShape($def);
            }
        }
        \ksort($out);
        return $out;
    }

    /** Compact parameter description usable by an LLM. */
    private function methodShape(array $def): array
    {
        $params = [];
        foreach (($def['params'] ?? []) as $p) {
            $name = $p['name'] ?? '';
            if ($name === '' || \str_contains($name, '#')) {
                continue; // skip flags markers
            }
            $type = $p['type'] ?? '?';
            if (!empty($p['subtype'])) {
                $type .= ' of '.$p['subtype'];
            }
            $why = [];
            if (!empty($p['flag'])) {
                $why[] = 'flag:'.$p['flag'].':'.((int) ($p['pow'] ?? 0));
            }
            $params[$name] = [
                'type' => $type,
                ...($why !== [] ? ['flags' => \implode(', ', $why)] : []),
            ];
        }
        return [
            'type' => $def['type'] ?? '?',
            'params' => $params,
        ];
    }

    /** Raw layer: invoke any method. */
    private function callRaw(array $args): mixed
    {
        $method = $args['method'] ?? null;
        if (!\is_string($method) || $method === '') {
            return ['_error' => true, 'message' => 'method is required'];
        }
        $callArgs = $args['args'] ?? [];
        return $this->twrap(fn () => $this->client->call($method, \is_array($callArgs) ? $callArgs : []));
    }

    /** Run a closure, normalizing thrown errors into a JSON-safe shape. */
    private function twrap(callable $fn): mixed
    {
        try {
            $result = $fn();
            if (\is_object($result) && \method_exists($result, 'toArray')) {
                return $result->toArray();
            }
            return $result;
        } catch (Throwable $e) {
            return [
                '_error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'class' => \get_class($e),
            ];
        }
    }
}