<?php

declare(strict_types=1);

namespace MadelineMcp\Assistant;

use MadelineMcp\ApiClient;
use MadelineMcp\ToolCatalog;

/**
 * Bridges the madeline-mcp ToolCatalog into OpenAI function-calling format
 * for the assistant agent loop. Tool names contain dots ("session.get_limits"),
 * which are illegal in OpenAI function names -> mapped to "session__get_limits".
 */
final class ToolBridge
{
    /** Tools that are useless/noisy inside an autonomous agent loop. */
    private const SKIP = ['list_methods', 'get_full_schema'];

    public function __construct(
        private readonly ApiClient $api,
        private readonly ToolCatalog $catalog,
    ) {
    }

    /**
     * MCP tools -> OpenAI tools array. Names sanitized; mapping is reversible.
     *
     * @return array<int, array{type:'function', function:array{name:string,description:string,parameters:array}}>
     */
    public function openaiTools(): array
    {
        $out = [];
        foreach ($this->catalog->all() as $tool) {
            $name = $tool['name'] ?? null;
            if (!\is_string($name) || \in_array($name, self::SKIP, true)) {
                continue;
            }
            $desc = (string) ($tool['description'] ?? '');
            // Compact per-arg hints into the description so the model sees them.
            foreach (($tool['inputSchema']['properties'] ?? []) as $pname => $p) {
                if (!empty($p['description']) && !\str_contains($desc, "$pname:")) {
                    $desc .= "\n$pname: {$p['description']}";
                }
            }
            $params = $tool['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()];
            if (empty($params['properties'])) {
                $params = ['type' => 'object', 'properties' => new \stdClass()];
            }
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => self::encode($name),
                    'description' => $desc,
                    'parameters' => $params,
                ],
            ];
        }
        return $out;
    }

    public static function encode(string $mcpName): string
    {
        return \str_replace('.', '__', $mcpName);
    }

    public static function decode(string $openaiName): string
    {
        return \str_replace('__', '.', $openaiName);
    }

    /** Execute one decoded tool call through the catalog. Never throws. */
    public function call(string $mcpName, array $args): array
    {
        try {
            $raw = $this->catalog->call($mcpName, $args);
            $d = \json_decode((string) $raw, true);
            return \is_array($d) ? $d : ['result' => $raw];
        } catch (\Throwable $e) {
            return ['_error' => $e->getMessage()];
        }
    }
}
