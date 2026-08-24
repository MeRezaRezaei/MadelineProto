<?php

declare(strict_types=1);

namespace MadelineMcp;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger;
use danog\MadelineProto\TL\TL;
use MadelineMcp\Settings\SettingsCatalog;
use MadelineMcp\Limits\CategoryMapper;
use MadelineMcp\Limits\LimitsCatalog;
use MadelineMcp\Limits\UsageTracker;
use MadelineMcp\Bots\BotCatalog;
use Throwable;
use MadelineMcp\SnapshotStore;

/**
 * Builds and dispatches MCP tools.
 *
 * Exposes a curated set of ergonomic high-level tools plus a raw layer
 * ("list_methods" / "call_method") that reaches every Telegram bot & user method.
 */
final class ToolCatalog
{
    private ?TL $tl = null;
    private string $currentTool = '';
    private string $currentSession = '';

    public function __construct(
        private readonly ApiClient $client,
        private readonly SettingsCatalog $settings = new SettingsCatalog(),
        private readonly LimitsCatalog $limits = new LimitsCatalog(),
        private readonly BotCatalog $bots = new BotCatalog(),
    ) {
    }

    /** Cooldown guard: returns an error payload when a lock is active. */
    private function guard(string $name, array $args): ?array
    {
        $map = CategoryMapper::map($name);
        if ($map === null) {
            return null;
        }
        $blocked = UsageTracker::forSession($this->resolveSession($args))->blocked($map['category']);
        if ($blocked === null) {
            return null;
        }
        return [
            '_error' => true,
            'code' => 420,
            'message' => 'cooldown_active: ' . $blocked['scope'] . ' FLOOD_WAIT lock until '
                . date('c', $blocked['until']) . " (" . $blocked['remaining'] . "s left). "
                . 'Inspect session.get_cooldowns / wait it out.',
            'flood' => true,
            'cooldown' => $blocked,
        ];
    }

    private function resolveSession(array $args): string
    {
        $s = $args['session_name'] ?? null;
        return (is_string($s) && $s !== '') ? $s : $this->client->defaultSession();
    }

    /**
     * Quota digest for proactive injection into MCP responses.
     * Returns null when nothing is relevant (unmapped tool, no cooldowns) so
     * read-only responses stay clean. Offline-only, never blocks the call.
     */
    public function quotaFor(string $name, array $args): ?array
    {
        try {
            $map = CategoryMapper::map($name);
            $digest = $this->limits->quotaDigest($this->client, $this->resolveSession($args));
            $relevant = $map !== null || $digest['cooldowns'] !== [];
            if (!$relevant) {
                return null;
            }
            return $digest;
        } catch (Throwable) {
            return null;
        }
    }

    private function recordUsage(string $name): void
    {
        $map = CategoryMapper::map($name);
        if ($map !== null) {
            try {
                UsageTracker::forSession($this->currentSession)->record($map['category']);
            } catch (Throwable) {
            }
        }
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
        return array_merge($this->settings->tools(), $this->limits->tools(), $this->bots->tools(), [
            [
                'name' => 'list_accounts',
                'description' => 'List all configured Telegram accounts/sessions and their login state.',
                'inputSchema' => self::objectSchema([]),
            ],
            [
                'name' => 'add_account',
                'description' => 'Add a Telegram account. If api_id/api_hash are omitted they are inherited from the primary session database (one app key, many accounts).',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::str('Unique identifier for this session.'),
                    'api_id' => self::int('Telegram app api_id (optional; inherited from the primary session if omitted).'),
                    'api_hash' => self::str('Telegram app api_hash (optional; inherited from the primary session if omitted).'),
                ], ['session_name']),
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
                'name' => 'list_folders',
                'description' => 'List Telegram chat folders (filters / chatlists) with id, title, kind, and peer counts.',
                'inputSchema' => self::objectSchema(['session_name' => self::sessionParam()]),
            ],
            [
                'name' => 'list_conversations',
                'description' => 'List conversations resolved to names with last-message preview and last-activity, sorted most-recent first. Each call returns a frozen-in-time sort_token: pass it back to get the NEXT slice of the SAME order (Telegram reshuffles positions constantly, so never re-list to paginate). Omit sort_token for a fresh current-moment sort. scope: "personal" (private 1-on-1 human chats), "all", or a numeric folder id.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'scope' => self::str('"personal", "all", or a numeric folder id.'),
                    'limit' => self::int('Max rows (default 20, max 50).'),
                    'include_bots' => self::bool('Include bot chats (default false).'),
                    'sort_token' => self::str('Frozen-sort token from a previous call; returns the next slice of the same order. Omit for a fresh sort.'),
                ]),
            ],
            [
                'name' => 'get_conversation',
                'description' => 'Read a conversation (peer id, username, or @username) as a clean, projected message list: id, date, sender, text/media, edited, reply. Returns a frozen-in-time sort_token; pass it back to load OLDER messages in the same stable order. Omit sort_token for the most recent messages. Descending into a chat means the parent listing is no longer needed.',
                'inputSchema' => self::objectSchema([
                    'session_name' => self::sessionParam(),
                    'peer' => self::str('Peer id, username, or @username.'),
                    'limit' => self::int('Max messages (default 20, max 100).'),
                    'sort_token' => self::str('Frozen-sort token from a previous call; returns the next (older) slice. Omit for the most recent messages.'),
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
        ]);
    }

    public function call(string $name, array $args): mixed
    {
        $this->currentTool = $name;
        $this->currentSession = $this->resolveSession($args);

        $guard = $this->guard($name, $args);
        if ($guard !== null) {
            return $guard;
        }

        if ($this->bots->has($name)) {
            return $this->twrap(fn () => $this->bots->dispatch($name, $args, $this->client));
        }

        if ($this->limits->has($name)) {
            $result = $this->twrap(fn () => $this->limits->dispatch($name, $args, $this->client));
            return $result;
        }

        if ($this->settings->has($name)) {
            $session = $args['session_name'] ?? null;
            // Wire FLOOD_WAIT recording for the settings layer.
            $this->settings->floodSink = UsageTracker::forSession($this->resolveSession($args));
            $result = $this->twrap(fn () => $this->settings->dispatch($name, $args, $this->api($session)));
            $this->recordUsage($name);
            return $result;
        }

        $result = match ($name) {
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
            'list_folders' => $this->listFolders($args),
            'list_conversations' => $this->listConversations($args),
            'get_conversation' => $this->getConversation($args),
            'list_methods' => $this->listMethods($args),
            'call_method' => $this->callRaw($args),
            default => ['_error' => true, 'message' => "Unknown tool: $name"],
        };
        $this->recordUsage($name);
        return $result;
    }

    private function addAccount(array $args): mixed
    {
        return $this->twrap(function () use ($args) {
            $apiId = isset($args['api_id']) ? (int) $args['api_id'] : null;
            $apiHash = isset($args['api_hash']) ? (string) $args['api_hash'] : null;
            $this->client->addAccountConfig($args['session_name'], $apiId, $apiHash);
            return ['status' => 'Account added (api key inherited from primary session database if not supplied). You can now start_login.'];
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

    /**
     * List Telegram chat folders (dialog filters / chatlists).
     */
    private function listFolders(array $args): array
    {
        return $this->twrap(function () use ($args) {
            $res = $this->client->call('messages.getDialogFilters', [], $args['session_name'] ?? null);
            $filters = $res['filters'] ?? [];
            $out = [];
            foreach ($filters as $f) {
                if (!isset($f['_']) || $f['_'] === 'dialogFilterDefault') {
                    continue;
                }
                $title = $f['title']['text'] ?? ($f['title'] ?? '');
                $kind = ($f['_'] === 'dialogFilterChatlist') ? 'chatlist' : 'filter';
                $out[] = [
                    'id' => $f['id'],
                    'title' => \is_array($title) ? ($title['text'] ?? '') : $title,
                    'emoticon' => $f['emoticon'] ?? '',
                    'kind' => $kind,
                    'pinned_count' => \count($f['pinned_peers'] ?? []),
                    'include_count' => \count($f['include_peers'] ?? []),
                ];
            }
            return ['folders' => $out];
        });
    }

    /**
     * List conversations resolved to names, with last-message preview and
     * last-activity, sorted most-recent first. Server-side projection keeps the
     * payload small so it is never truncated downstream.
     */
    private function listConversations(array $args): array
    {
        return $this->twrap(function () use ($args) {
            $session = $args['session_name'] ?? null;
            $scope = $args['scope'] ?? 'personal';
            $limit = min((int) ($args['limit'] ?? 20), 50);
            $includeBots = (bool) ($args['include_bots'] ?? false);
            $token = $args['sort_token'] ?? null;

            if (\is_string($token)) {
                if (!SnapshotStore::exists($token)) {
                    return ['_error' => true, 'message' => 'sort_token expired or unknown; call without sort_token for a fresh (current-moment) sort.'];
                }
                $page = SnapshotStore::take($token, $limit);

                return [
                    'total' => $page['total'],
                    'returned' => $page['returned'],
                    'conversations' => $page['items'],
                    'sort_token' => $token,
                    'page_done' => $page['done'],
                ];
            }

            $api = $this->api($session);
            $raw = $api->messages->getDialogs([
                'limit' => 200,
                'offset_date' => 0,
                'offset_id' => 0,
                'offset_peer' => ['_' => 'inputPeerEmpty'],
            ]);

            $dialogs = $raw['dialogs'] ?? [];
            $messages = [];
            foreach ($raw['messages'] ?? [] as $m) {
                $messages[$m['id']] = $m;
            }
            $users = [];
            foreach ($raw['users'] ?? [] as $u) {
                $users[$u['id']] = $u;
            }
            $chats = [];
            foreach ($raw['chats'] ?? [] as $c) {
                $chats[$c['id']] = $c;
            }

            $allowed = null;
            if (is_numeric($scope)) {
                $allowed = $this->folderPeerSet((int) $scope, $session);
            }

            $rows = [];
            foreach ($dialogs as $d) {
                $pid = $d['peer'] ?? null;
                if (!\is_int($pid)) {
                    continue;
                }
                [$type, $name, $username] = $this->resolvePeerInfo($pid, $users, $chats);
                if ($type === null) {
                    continue;
                }
                if ($type === 'bot' && !$includeBots) {
                    continue;
                }
                if (\is_numeric($scope)) {
                    if ($allowed !== null && !isset($allowed[$pid])) {
                        continue;
                    }
                } elseif ($scope === 'personal') {
                    if ($type !== 'user') {
                        continue;
                    }
                }

                $msg = $messages[$d['top_message']] ?? null;
                $date = $msg['date'] ?? 0;
                $preview = '';
                if ($msg) {
                    if (isset($msg['message']) && \is_string($msg['message'])) {
                        $preview = \mb_substr($msg['message'], 0, 80);
                    } elseif (isset($msg['action'])) {
                        $preview = '[' . ($msg['action']['_'] ?? 'action') . ']';
                    }
                }
                $rows[] = [
                    'id' => $pid,
                    'name' => $name,
                    'username' => $username,
                    'type' => $type,
                    'preview' => $preview,
                    'last_activity' => $date,
                    'unread' => $d['unread_count'] ?? 0,
                    'pinned' => (bool) ($d['pinned'] ?? false),
                ];
            }

            \usort($rows, fn ($a, $b) => $b['last_activity'] <=> $a['last_activity']);
            $newToken = SnapshotStore::create($rows, ['scope' => $scope, 'session' => $session]);
            $page = SnapshotStore::take($newToken, $limit);

            return [
                'total' => $page['total'],
                'returned' => $page['returned'],
                'conversations' => $page['items'],
                'sort_token' => $newToken,
                'page_done' => $page['done'],
            ];
        });
    }

    private function resolvePeerInfo(int $pid, array $users, array $chats): array
    {
        if ($pid > 0) {
            $u = $users[$pid] ?? null;
            if (!$u) {
                return [null, null, null];
            }
            $name = \trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            $name = $name ?: ($u['username'] ?? (string) $pid);

            return [!empty($u['bot']) ? 'bot' : 'user', $name, $u['username'] ?? null];
        }
        if (\str_starts_with((string) $pid, '-100')) {
            $c = $chats[\abs($pid)] ?? null;
            if (!$c) {
                return [null, null, null];
            }

            return ['channel', $c['title'] ?? (string) $pid, null];
        }
        $c = $chats[\abs($pid)] ?? null;
        if (!$c) {
            return [null, null, null];
        }

        return ['group', $c['title'] ?? (string) $pid, null];
    }

    private function folderPeerSet(int $folderId, ?string $session): ?array
    {
        $res = $this->client->call('messages.getDialogFilters', [], $session);
        foreach ($res['filters'] ?? [] as $f) {
            if (($f['id'] ?? null) != $folderId) {
                continue;
            }
            $set = [];
            foreach (\array_merge($f['pinned_peers'] ?? [], $f['include_peers'] ?? []) as $p) {
                $id = $this->peerIdFromInput($p);
                if ($id !== null) {
                    $set[$id] = true;
                }
            }

            return $set;
        }

        return [];
    }

    private function peerIdFromInput(array $p): ?int
    {
        switch ($p['_'] ?? '') {
            case 'inputPeerUser':
                return (int) ($p['user_id'] ?? null);
            case 'inputPeerChat':
                return -\abs((int) ($p['chat_id'] ?? 0));
            case 'inputPeerChannel':
                return -(1000000000000 + (int) ($p['channel_id'] ?? 0));
            default:
                return null;
        }
    }

    /**
     * Read a conversation as a clean, projected message list, paginated over a
     * frozen-in-time snapshot so the AI continues a stable order. Passing the
     * sort_token back loads OLDER messages; omitting it starts fresh (most
     * recent). Descending into a chat lets the AI drop the parent listing.
     */
    private function getConversation(array $args): array
    {
        return $this->twrap(function () use ($args) {
            $session = $args['session_name'] ?? null;
            $peer = $args['peer'] ?? null;
            if ($peer === null || $peer === '') {
                throw new \Exception('peer is required');
            }
            $limit = min((int) ($args['limit'] ?? 20), 100);
            $token = $args['sort_token'] ?? null;

            if (\is_string($token)) {
                if (!SnapshotStore::exists($token)) {
                    return ['_error' => true, 'message' => 'sort_token expired or unknown; call without sort_token for a fresh (current-moment) sort.'];
                }
                $page = SnapshotStore::take($token, $limit);
                if ($page['done']) {
                    // Stored window exhausted: transparently extend with older
                    // messages so pagination keeps returning a stable order.
                    $meta = SnapshotStore::meta($token);
                    $oldestId = (int) ($meta['oldest_id'] ?? 0);
                    if ($oldestId > 0) {
                        $api = $this->api($session);
                        $raw = $api->messages->getHistory([
                            'peer' => $meta['peer'],
                            'limit' => $limit,
                            'offset_id' => $oldestId,
                            'offset_date' => 0,
                            'add_offset' => 0,
                            'max_id' => 0,
                            'min_id' => 0,
                        ]);
                        $peerId = (int) ($meta['peer_info']['id'] ?? 0);
                        $newRows = $this->buildMessageRows($raw, $peerId);
                        $newOldest = \count($newRows) < $limit ? 0 : $this->minMessageId($newRows, $oldestId);
                        SnapshotStore::extend($token, $newRows, [
                            'peer' => $meta['peer'],
                            'session' => $session,
                            'oldest_id' => $newOldest,
                            'peer_info' => $meta['peer_info'],
                            'true_count' => $meta['true_count'] ?? null,
                        ]);
                        $page = SnapshotStore::take($token, $limit);
                    }
                }

                return $this->formatConversationPage($page, $token);
            }

            $api = $this->api($session);
            $raw = $api->messages->getHistory([
                'peer' => $peer,
                'limit' => $limit,
                'offset_id' => 0,
                'offset_date' => 0,
                'add_offset' => 0,
                'max_id' => 0,
                'min_id' => 0,
            ]);

            $peerId = $this->resolvePeerId($peer, $raw['users'] ?? [], $raw['chats'] ?? []);
            [$peerType, $peerName] = $this->resolvePeerInfo((int) $peerId, $raw['users'] ?? [], $raw['chats'] ?? []);
            $rows = $this->buildMessageRows($raw, (int) $peerId);
            $oldestId = $this->minMessageId($rows, 0);
            $newToken = SnapshotStore::create($rows, [
                'peer' => $peer,
                'session' => $session,
                'oldest_id' => $oldestId,
                'peer_info' => ['id' => $peerId, 'name' => $peerName, 'type' => $peerType],
                'true_count' => $raw['count'] ?? \count($rows),
            ]);
            $page = SnapshotStore::take($newToken, $limit);

            return $this->formatConversationPage($page, $newToken);
        });
    }

    private function buildMessageRows(array $raw, int $peerId): array
    {
        $messages = $raw['messages'] ?? [];
        $users = [];
        foreach ($raw['users'] ?? [] as $u) {
            $users[$u['id']] = $u;
        }
        $chats = [];
        foreach ($raw['chats'] ?? [] as $c) {
            $chats[$c['id']] = $c;
        }

        $rows = [];
        foreach ($messages as $m) {
            $fromId = $m['from_id'] ?? $peerId;
            if (!\is_int($fromId)) {
                $fromId = $peerId;
            }
            [$ftype, $fname] = $this->resolvePeerInfo((int) $fromId, $users, $chats);

            $text = '';
            $mediaType = 'text';
            if (isset($m['message']) && \is_string($m['message']) && $m['message'] !== '') {
                $text = $m['message'];
            } elseif (isset($m['media'])) {
                $mediaType = 'media:' . ($m['media']['_'] ?? 'unknown');
                $text = $m['message'] ?? '';
            } elseif (isset($m['action'])) {
                $mediaType = 'action:' . ($m['action']['_'] ?? 'unknown');
            }

            $rows[] = [
                'id' => $m['id'],
                'date' => $m['date'] ?? 0,
                'out' => (bool) ($m['out'] ?? false),
                'from' => ['id' => $fromId, 'name' => $fname, 'type' => $ftype],
                'media_type' => $mediaType,
                'text' => $text,
                'edited' => isset($m['edit_date']),
                'reply_to' => ($m['reply_to']['reply_to_msg_id'] ?? null),
            ];
        }

        return $rows;
    }

    private function formatConversationPage(array $page, string $token): array
    {
        $meta = $page['meta'] ?? [];
        $peerInfo = $meta['peer_info'] ?? ['id' => null, 'name' => null, 'type' => null];
        $storeDone = $page['done'];
        $canExtend = ($meta['oldest_id'] ?? 0) > 0;

        return [
            'peer' => $peerInfo,
            'count' => $meta['true_count'] ?? $page['total'],
            'loaded' => $page['total'],
            'returned' => $page['returned'],
            'messages' => $page['items'],
            'sort_token' => $token,
            'page_done' => $storeDone && !$canExtend,
        ];
    }

    private function minMessageId(array $rows, int $fallback): int
    {
        $min = null;
        foreach ($rows as $r) {
            $id = $r['id'] ?? null;
            if ($id === null) {
                continue;
            }
            if ($min === null || $id < $min) {
                $min = (int) $id;
            }
        }

        return $min ?? $fallback;
    }

    private function resolvePeerId($peer, array $users, array $chats): ?int
    {
        if (\is_numeric($peer)) {
            return (int) $peer;
        }
        $p = \ltrim((string) $peer, '@');
        foreach ($users as $u) {
            if (($u['username'] ?? null) === $p) {
                return $u['id'];
            }
        }
        foreach ($chats as $c) {
            if (($c['username'] ?? null) === $p) {
                return $c['id'];
            }
        }

        return null;
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
            // Auto-record FLOOD_WAITs into the cooldown tracker.
            $sec = UsageTracker::floodSeconds($e);
            if ($sec !== null && $this->currentSession !== '') {
                $cat = CategoryMapper::map($this->currentTool)['category'] ?? null;
                UsageTracker::forSession($this->currentSession)->recordFloodWait($sec, $this->currentTool, $cat);
            }
            return [
                '_error' => true,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'class' => \get_class($e),
                'flood_wait_seconds' => $sec,
            ];
        }
    }
}