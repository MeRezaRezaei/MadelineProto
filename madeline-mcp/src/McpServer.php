<?php

declare(strict_types=1);

namespace MadelineMcp;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Revolt\EventLoop;
use Throwable;

use function Amp\async;

/**
 * Minimal MCP stdio server.
 *
 * Wire format: newline-delimited JSON-RPC 2.0 over stdin/stdout.
 */
final class McpServer
{
    public const PROTOCOL_VERSION = '2024-11-05';

    private string $buffer = '';

    public function __construct(
        private readonly ApiClient $client,
        private readonly ToolCatalog $catalog,
    ) {
    }

    public function run(): void
    {
        $stdin = new ReadableResourceStream(\STDIN);
        $stdout = new WritableResourceStream(\STDOUT);

        async(function () use ($stdin, $stdout): void {
            while (($chunk = $stdin->read()) !== null) {
                $this->buffer .= $chunk;
                $this->drain($stdout);
            }
            $this->drain($stdout);
        });

        EventLoop::run();
    }

    private function drain(WritableResourceStream $stdout): void
    {
        while (($nl = \strpos($this->buffer, "\n")) !== false) {
            $line = \trim(\substr($this->buffer, 0, $nl));
            $this->buffer = \substr($this->buffer, $nl + 1);
            if ($line === '') {
                continue;
            }
            $response = $this->handleLine($line);
            if ($response !== null) {
                $stdout->write(\json_encode($response, \JSON_UNESCAPED_SLASHES)."\n");
            }
        }
    }

    /** Process a single JSON-RPC line (testable without a live stream). */
    public function processLine(string $line): ?array
    {
        return $this->handleLine($line);
    }

    /** @return array|null JSON-RPC response, null for notifications. */
    private function handleLine(string $line): ?array
    {
        try {
            $msg = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->error(null, -32700, 'Parse error: invalid JSON');
        }

        $id = $msg['id'] ?? null;
        $method = $msg['method'] ?? null;

        if (!\is_string($method)) {
            return null;
        }

        try {
            return $this->route($id, $method, $msg['params'] ?? []);
        } catch (Throwable $e) {
            return $this->error($id, -32603, 'Internal error: '.$e->getMessage());
        }
    }

    private function route(mixed $id, string $method, array $params): ?array
    {
        return match ($method) {
            'initialize' => $this->respond($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'madeline-mcp', 'version' => '1.0.0'],
            ]),
            'notifications/initialized' => null,
            'ping' => $this->respond($id, new \stdClass()),
            'tools/list' => $this->respond($id, [
                'tools' => $this->catalog->all(),
            ]),
            'tools/call' => $this->callTool($id, $params),
            default => $this->error($id, -32601, "Method not found: $method"),
        };
    }

    private function callTool(mixed $id, array $params): ?array
    {
        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];

        if (!\is_string($name) || !\is_array($args)) {
            return $this->error($id, -32602, 'Invalid params: name and arguments required');
        }

        $result = $this->catalog->call($name, $args);

        // AI-facing shaping unless explicitly disabled.
        if (\getenv('MADELINE_MCP_RAW') !== '1') {
            $result = ResponseSanitizer::project($name, $result);
            $result = ResponseSanitizer::clean($result);
        }

        // Proactive quota injection: budget state rides along with every
        // relevant response so the AI can plan ahead instead of reacting to
        // errors after the fact. Array results gain a _quota key; scalars get
        // a trailing QUOTA text block. Injection never alters the call itself.
        $quota = $this->catalog->quotaFor($name, $args);
        if ($quota !== null) {
            if (\is_array($result)) {
                $result['_quota'] = $quota;
                $text = \json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                return $this->respond($id, [
                    'content' => [['type' => 'text', 'text' => (string) $text]],
                ]);
            }
            $text = \json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            return $this->respond($id, [
                'content' => [
                    ['type' => 'text', 'text' => (string) $text],
                    ['type' => 'text', 'text' => 'QUOTA ' . \json_encode($quota, JSON_UNESCAPED_SLASHES)],
                ],
            ]);
        }

        $text = \json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $this->respond($id, [
            'content' => [['type' => 'text', 'text' => (string) $text]],
        ]);
    }

    private function respond(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}