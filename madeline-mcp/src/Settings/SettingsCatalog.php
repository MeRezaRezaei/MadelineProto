<?php

declare(strict_types=1);

namespace MadelineMcp\Settings;

use danog\MadelineProto\API;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

/**
 * DDD settings layer.
 *
 * Telegram organises everything by *namespace* (account, messages, auth, bots,
 * ...). Each namespace is a bounded context. This catalog mirrors that design
 * 1:1: every exposed MCP tool is named exactly like the underlying Telegram
 * method (`account.updateProfile`, `messages.getPeerSettings`, `auth.logOut`,
 * ...) so an LLM can map a user's intent onto Telegram's own API without a
 * translation layer.
 *
 * Tool definitions and argument binding are derived by reflection over
 * MadelineProto's high-level namespace classes, keeping them faithful to the
 * real method signatures.
 */
final class SettingsCatalog
{
    /** Bounded contexts (Telegram namespaces) exposed as settings. */
    private const CONTEXTS = [
        'account' => \danog\MadelineProto\Namespace\Account::class,
        'messages' => \danog\MadelineProto\Namespace\Messages::class,
        'auth' => \danog\MadelineProto\Namespace\Auth::class,
        'bots' => \danog\MadelineProto\Namespace\Bots::class,
    ];

    /** Methods (within a context) to expose. null = all except EXCLUDE. */
    private const INCLUDE = [
        'account' => null,
        'messages' => [
            'getPeerSettings', 'saveDraft', 'readHistory', 'readMessageContents',
            'pinMessage', 'unpinAllMessages', 'markDialogUnread', 'getDialogFilters',
            'updateDialogFilter', 'updateDialogFiltersOrder', 'getPinnedDialogs',
            'updatePinnedDialogs', 'saveDefaultSendAs', 'getSavedHistory',
            'getDialogUnreadMarks', 'getDefaultHistoryTTL', 'setDefaultHistoryTTL',
            'getFavedStickers', 'faveSticker', 'getSavedGifs', 'saveGif',
        ],
        'auth' => ['logOut'],
        'bots' => [
            'getBotCommands', 'setBotCommands', 'getBotMenuButton', 'setBotMenuButton',
            'toggleBotInAttachMenu', 'getBotInfo', 'setBotInfo',
        ],
    ];

    /** Account methods that require a file upload / are out of settings scope. */
    private const EXCLUDE = [
        'uploadWallPaper', 'uploadTheme', 'uploadRingtone', 'saveMusic',
        'initTakeoutSession', 'finishTakeoutSession',
        'getAuthorizationForm', 'acceptAuthorization',
        'reportPeer', 'reportProfilePhoto',
        'sendVerifyPhoneCode', 'verifyPhone', 'verifyEmail', 'sendVerifyEmailCode',
        'getPaidMessagesRevenue', 'toggleNoPaidMessagesException',
        'getUniqueGiftChatThemes', 'getCollectibleEmojiStatuses',
    ];

    /** Params that are transport/loop internals, never user-facing. */
    private const INTERNAL = ['floodWaitLimit', 'queueId', 'cancellation', 'takeoutId'];

    /** Tools the LLM drives itself (local MCP lifecycle, not Telegram). */
    private const LOCAL_TOOLS = [
        'session.remove_account' => 'Delete a locally stored MadelineProto session for an account (does NOT delete the Telegram account).',
    ];

    /** Telegram methods that are not plain namespace methods (custom bindings). */
    private const SPECIAL_TOOLS = [
        'auth.logOut' => 'Log out the current account (terminates the Telegram session).',
    ];

    /** @return array<int, array{name:string,description:string,inputSchema:array}> */
    public function tools(): array
    {
        $out = [];
        foreach (self::LOCAL_TOOLS as $name => $desc) {
            $out[] = [
                'name' => $name,
                'description' => $desc,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) [
                        'session_name' => ['type' => 'string', 'description' => 'Session to remove.'],
                    ],
                    'required' => ['session_name'],
                ],
            ];
        }
        foreach (self::CONTEXTS as $ns => $class) {
            foreach ($this->methods($ns) as $method) {
                $out[] = $this->describe($ns, $class, $method);
            }
        }
        foreach (self::SPECIAL_TOOLS as $name => $desc) {
            $out[] = [
                'name' => $name,
                'description' => $desc,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) ['session_name' => ['type' => 'string', 'description' => 'Optional account session to target (defaults to primary).']],
                ],
            ];
        }
        return $out;
    }

    public function has(string $tool): bool
    {
        if (isset(self::LOCAL_TOOLS[$tool]) || isset(self::SPECIAL_TOOLS[$tool])) {
            return true;
        }
        if (!\str_contains($tool, '.')) {
            return false;
        }
        [$ns, $method] = \explode('.', $tool, 2);
        return isset(self::CONTEXTS[$ns]) && \in_array($method, $this->methods($ns), true);
    }

    public function dispatch(string $tool, array $args, API $api): mixed
    {
        if (isset(self::LOCAL_TOOLS[$tool])) {
            return $this->removeAccount($args);
        }
        if (isset(self::SPECIAL_TOOLS[$tool])) {
            return $this->dispatchSpecial($tool, $args, $api);
        }
        [$ns, $method] = \explode('.', $tool, 2);
        $obj = $api->{$ns};
        $ref = new ReflectionMethod(self::CONTEXTS[$ns], $method);

        $ordered = [];
        $missing = [];
        foreach ($ref->getParameters() as $p) {
            $name = $p->getName();
            if (\in_array($name, self::INTERNAL, true)) {
                continue;
            }
            if (\array_key_exists($name, $args)) {
                $ordered[] = $args[$name];
            } elseif ($p->isOptional()) {
                $ordered[] = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
            } else {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            return ['_error' => true, 'message' => 'Missing required parameters: ' . \implode(', ', $missing)];
        }

        try {
            $result = $obj->{$method}(...$ordered);
            return \is_object($result) && \method_exists($result, 'toArray') ? $result->toArray() : $result;
        } catch (Throwable $e) {
            return ['_error' => true, 'code' => $e->getCode(), 'message' => $e->getMessage(), 'class' => \get_class($e)];
        }
    }

    private function removeAccount(array $args): mixed
    {
        $name = $args['session_name'] ?? null;
        if (!\is_string($name) || $name === '') {
            return ['_error' => true, 'message' => 'session_name is required'];
        }
        $path = \getcwd() . '/sessions/' . $name;
        if (!\is_dir($path)) {
            return ['_error' => true, 'message' => "No local session named '$name'."];
        }
        $ok = $this->deleteDir($path);
        return ['status' => $ok ? "Local session '$name' removed." : 'Removed partially; some files locked.'];
    }

    private function dispatchSpecial(string $tool, array $args, API $api): mixed
    {
        try {
            if ($tool === 'auth.logOut') {
                $api->logout();
                return ['status' => 'Logged out.'];
            }
            return ['_error' => true, 'message' => "Unknown special tool: $tool"];
        } catch (Throwable $e) {
            return ['_error' => true, 'code' => $e->getCode(), 'message' => $e->getMessage(), 'class' => \get_class($e)];
        }
    }

    private function deleteDir(string $path): bool
    {
        $items = \array_diff(\scandir($path) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $full = $path . '/' . $item;
            \is_dir($full) ? $this->deleteDir($full) : @\unlink($full);
        }
        return @\rmdir($path);
    }

    /** @return list<string> */
    private function methods(string $ns): array
    {
        $all = \array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(self::CONTEXTS[$ns]))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        $include = self::INCLUDE[$ns];
        if ($include === null) {
            return \array_values(\array_filter(
                $all,
                static fn (string $m): bool => !\in_array($m, self::EXCLUDE, true)
                    && !\str_contains($m, 'Business')
                    && !\str_contains($m, 'Passkey')
                    && !\str_contains($m, 'SecureValue')
                    && !\str_contains($m, 'ConnectedBot')
                    && !\str_starts_with($m, 'upload'),
            ));
        }
        return array_values(array_filter($include, static fn (string $m): bool => method_exists(self::CONTEXTS[$ns], $m)));
    }

    private function describe(string $ns, string $class, string $method): array
    {
        $ref = new ReflectionMethod($class, $method);
        $props = [];
        $required = [];
        foreach ($ref->getParameters() as $p) {
            $name = $p->getName();
            if (\in_array($name, self::INTERNAL, true)) {
                continue;
            }
            $props[$name] = ['type' => $this->jsonType($p), 'description' => $name];
            if (!$p->isOptional()) {
                $required[] = $name;
            }
        }
        $props['session_name'] = ['type' => 'string', 'description' => 'Optional account session to target (defaults to primary).'];
        return [
            'name' => $ns . '.' . $method,
            'description' => "Telegram $ns.$method (account/peer setting).",
            'inputSchema' => [
                'type' => 'object',
                'properties' => (object) $props,                ...($required !== [] ? ['required' => $required] : []),
            ],
        ];
    }

    private function jsonType(ReflectionParameter $p): string
    {
        $t = $p->getType();
        if (!$t instanceof ReflectionNamedType) {
            return 'string';
        }
        return match ($t->getName()) {
            'int' => 'integer',
            'float', 'double' => 'number',
            'bool' => 'boolean',
            'array' => 'object',
            'string', 'mixed' => 'string',
            default => 'string',
        };
    }
}
