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

    /** Standard parameter for multi-account support. */
    private static function sessionParam(): array
    {
        return self::str('Optional session name. Defaults to the primary background session.');
    }

    /** The full list of tools advertised to the client. */
    public function all(): array
    {
        return [
            [
                'name' => 'list_accounts',
                'description' => 'List all configured Telegram accounts/sessions and their login state.',
                'inputSchema' => self::objectSchema([]),
            ],
            [
                'name' => 'add_account',
                'description' => 'Add a new Telegram API account to the MCP server configuration.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::str('Unique identifier for this session.'),
                    'api_id' => self::int('Telegram app api_id.'),
                    'api_hash' => self::str('Telegram app api_hash.'),
                ], ['session_name', 'api_id', 'api_hash']),
            ],
            [
                'name' => 'start_login',
                'description' => 'Start the login process (sends SMS code or logs in bot). Use submit_login_code next if phone.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::str('The session to log in.'),
                    'phone' => self::str('Phone number (if user account).'),
                    'bot_token' => self::str('Bot token (if bot account).'),
                ], ['session_name']),
            ],
            [
                'name' => 'submit_login_code',
                'description' => 'Submit the SMS or Telegram verification code to finish login.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::str('The session being logged in.'),
                    'code' => self::str('The verification code.'),
                ], ['session_name', 'code']),
            ],
            [
                'name' => 'submit_password',
                'description' => 'Submit the 2FA password if the account requires it for login.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::str('The session being logged in.'),
                    'password' => self::str('The 2FA password.'),
                ], ['session_name', 'password']),
            ],
            [
                'name' => 'get_login_state',
                'description' => 'Return the current login state and identity.',
                'inputSchema' => self::objectSchema(['session_name' => self::sessionParam()]),
            ],
            [
                'name' => 'get_me',
                'description' => 'Return the logged-in account (user or bot) information.',
                'inputSchema' => self::objectSchema(['session_name' => self::sessionParam()]),
            ],
            [
                'name' => 'list_dialogs',
                'description' => 'List recent chats/dialogs (gives peer IDs usable in send_message).',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'limit' => self::int('Max number of dialogs (default 20).'),
                ]),
            ],
            [
                'name' => 'send_message',
                'description' => 'Send a text message to a peer (user, group, channel, username, or @username).',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'peer' => self::str('Target peer: id, username, @username, or phone.'),
                    'message' => self::str('Text of the message.'),
                    'parse_mode' => self::str('Optional parse mode: Markdown or HTML.'),
                    'reply_to' => self::int('Optional message id to reply to.'),
                ], ['peer', 'message']),
            ],
            [
                'name' => 'send_media',
                'description' => 'Send a local file / media to a peer.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'peer' => self::str('Target peer: id, username, @username, or phone.'),
                    'file_path' => self::str('Absolute path to the local file to send.'),
                    'message' => self::str('Optional caption text.'),
                ], ['peer', 'file_path']),
            ],
            [
                'name' => 'download_media',
                'description' => 'Download media from a message to a local file.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'peer' => self::str('Chat peer: id, username, or @username.'),
                    'message_id' => self::int('The ID of the message containing the media.'),
                    'output_path' => self::str('Absolute path to where the file should be saved.'),
                ], ['peer', 'message_id', 'output_path']),
            ],
            [
                'name' => 'delete_messages',
                'description' => 'Delete one or more messages in a chat.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'peer' => self::str('Chat peer: id, username, or @username.'),
                    'message_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'List of message IDs to delete.'
                    ],
                    'revoke' => self::bool('Whether to delete for everyone (default true).'),
                ], ['peer', 'message_ids']),
            ],
            [
                'name' => 'read_history',
                'description' => 'Read recent messages of a chat.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'peer' => self::str('Chat: id, username, or @username.'),
                    'limit' => self::int('Max messages (default 30).'),
                ], ['peer']),
            ],
            [
                'name' => 'resolve_peer',
                'description' => 'Resolve a peer (id / username / @username / phone) into its full info.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'peer' => self::str('Peer to resolve.'),
                ], ['peer']),
            ],
            [
                'name' => 'search_messages',
                'description' => 'Search messages inside a chat.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'peer' => self::str('Chat to search in.'),
                    'query' => self::str('Search text.'),
                    'limit' => self::int('Max results (default 30).'),
                ], ['peer']),
            ],
            [
                'name' => 'get_full_chat_info',
                'description' => 'Get detailed metadata about a chat (participants count, photo, etc.).',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
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
                'description' => 'Call ANY Telegram method by dotted name. Use list_methods to discover names and params.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
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
            'list_accounts' => $this->client->listAccounts(),
            'add_account' => $this->addAccount($args),
            'start_login' => $this->startLogin($args),
            'submit_login_code' => $this->submitLoginCode($args),
            'submit_password' => $this->submitPassword($args),
            'get_login_state' => $this->getLoginState($args),
            'get_me' => $this->getMe($args),
            'list_dialogs' => $this->listDialogs($args),
            'send_message' => $this->sendMessage($args),
            'send_media' => $this->sendMedia($args),
            'download_media' => $this->downloadMedia($args),
            'delete_messages' => $this->deleteMessages($args),
            'read_history' => $this->readHistory($args),
            'resolve_peer' => $this->resolvePeer($args),
            'search_messages' => $this->searchMessages($args),
            'get_full_chat_info' => $this->getFullChatInfo($args),
            'list_methods' => $this->listMethods($args),
            'call_method' => $this->callRaw($args),
            default => ['_error' => true, 'message' => "Unknown tool: $name"],
        };
    }

    private function addAccount(array $args): mixed
    {
        return $this->twrap(function () use ($args) {
            $this->client->addAccountConfig($args['session_name'], $args['api_id'], $args['api_hash']);
            return ['status' => 'Account added and persisted to the MadelineProto session database. You can now start_login.'];
        });
    }

    private function startLogin(array $args): mixed
    {
        return $this->twrap(function () use ($args) {
            $api = $this->client->api($args['session_name']);
            if (!empty($args['bot_token'])) {
                $api->botLogin($args['bot_token']);
                return ['status' => 'Logged in as bot successfully.'];
            }
            if (!empty($args['phone'])) {
                $res = $api->phoneLogin($args['phone']);
                return ['status' => 'Code sent. Please call submit_login_code.', 'details' => $res];
            }
            return ['_error' => true, 'message' => 'Must provide either bot_token or phone.'];
        });
    }

    private function submitLoginCode(array $args): mixed
    {
        return $this->twrap(function () use ($args) {
            $api = $this->client->api($args['session_name']);
            $api->completePhoneLogin($args['code']);
            return ['status' => 'Login complete. Session activated.'];
        });
    }

    private function submitPassword(array $args): mixed
    {
        return $this->twrap(function () use ($args) {
            $api = $this->client->api($args['session_name']);
            $api->complete2faLogin($args['password']);
            return ['status' => '2FA complete. Session activated.'];
        });
    }

    private function api(?string $session = null): API
    {
        return $this->client->api($session);
    }

    private function getLoginState(array $args): mixed
    {
        return $this->twrap(function () use ($args): array {
            $api = $this->api($args['session_name'] ?? null);
            $auth = $api->getAuthorization();
            $me = $api->getSelf();
            $loggedIn = \is_array($me) && $auth === API::LOGGED_IN;
            return [
                'state' => match ($auth) {
                    API::LOGGED_IN => 'LOGGED_IN',
                    API::WAITING_CODE => 'WAITING_CODE',
                    API::WAITING_PASSWORD => 'WAITING_PASSWORD',
                    API::WAITING_SIGNUP => 'WAITING_SIGNUP',
                    API::LOGGED_OUT => 'LOGGED_OUT',
                    default => 'NOT_LOGGED_IN',
                },
                'logged_in' => $loggedIn,
            ];
        });
    }

    private function getMe(array $args): mixed
    {
        return $this->twrap(fn () => $this->api($args['session_name'] ?? null)->getSelf());
    }

    private function resolvePeer(array $args): mixed
    {
        return $this->twrap(fn () => $this->api($args['session_name'] ?? null)->getInfo($args['peer']));
    }

    private function listDialogs(array $args): mixed
    {
        $limit = \min((int) ($args['limit'] ?? 20), 200);
        return $this->twrap(fn () => $this->api($args['session_name'] ?? null)->getDialogIds(0, $limit));
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
        return $this->twrap(fn () => $this->api($args['session_name'] ?? null)->messages->sendMessage($arg));
    }

    private function sendMedia(array $args): mixed
    {
        $arg = [
            'peer' => $args['peer'],
            'media' => $args['file_path'],
            'message' => $args['message'] ?? '',
        ];
        return $this->twrap(fn () => $this->api($args['session_name'] ?? null)->messages->sendMedia($arg));
    }

    private function downloadMedia(array $args): mixed
    {
        return $this->twrap(function () use ($args) {
            $api = $this->api($args['session_name'] ?? null);
            $parsedPeer = $api->getInfo($args['peer']);
            
            if ($parsedPeer['type'] === 'channel' || $parsedPeer['type'] === 'supergroup') {
                $res = $api->channels->getMessages([
                    'channel' => $args['peer'],
                    'id' => [$args['message_id']],
                ]);
            } else {
                $res = $api->messages->getMessages([
                    'id' => [$args['message_id']],
                ]);
            }
            
            $msg = $res['messages'][0] ?? null;
            if (!$msg || (isset($msg['_']) && $msg['_'] === 'messageEmpty')) {
                return ['_error' => true, 'message' => 'Message not found or empty.'];
            }
            $file = $api->downloadToFile($msg, $args['output_path']);
            return ['file' => $file];
        });
    }

    private function deleteMessages(array $args): mixed
    {
        return $this->twrap(function () use ($args) {
            $api = $this->api($args['session_name'] ?? null);
            $parsedPeer = $api->getInfo($args['peer']);
            if ($parsedPeer['type'] === 'channel' || $parsedPeer['type'] === 'supergroup') {
                $res = $api->channels->deleteMessages([
                    'channel' => $args['peer'],
                    'id' => $args['message_ids']
                ]);
            } else {
                $res = $api->messages->deleteMessages([
                    'id' => $args['message_ids'],
                    'revoke' => $args['revoke'] ?? true
                ]);
            }
            return $res;
        });
    }

    private function readHistory(array $args): mixed
    {
        $limit = \min((int) ($args['limit'] ?? 30), 200);
        return $this->twrap(fn () => $this->api($args['session_name'] ?? null)->messages->getHistory([
            'peer' => $args['peer'],
            'limit' => $limit,
        ]));
    }

    private function searchMessages(array $args): mixed
    {
        $limit = \min((int) ($args['limit'] ?? 30), 200);
        return $this->twrap(fn () => $this->api($args['session_name'] ?? null)->messages->search([
            'peer' => $args['peer'],
            'q' => $args['query'],
            'limit' => $limit,
        ]));
    }

    private function getFullChatInfo(array $args): mixed
    {
        return $this->twrap(fn () => $this->api($args['session_name'] ?? null)->getPwrChat($args['peer']));
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
        return $this->twrap(fn () => $this->client->call($method, \is_array($callArgs) ? $callArgs : [], $args['session_name'] ?? null));
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