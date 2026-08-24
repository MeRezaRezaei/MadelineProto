<?php

declare(strict_types=1);

namespace MadelineMcp\Assistant;

use RuntimeException;
use Throwable;

/**
 * Minimal OpenAI-compatible client for the local OmniRoute gateway.
 * Config resolution order: env OMNIROUTE_BASE/OMNIROUTE_API_KEY/OMNIROUTE_MODEL,
 * then ~/.config/madeline-mcp/omniroute.json. Never hardcode credentials here.
 */
final class OmniClient
{
    public string $base;
    public string $key;
    public string $model;

    public function __construct(?string $model = null)
    {
        $cfg = self::config();
        $this->base = \rtrim($cfg['base'], '/');
        $this->key = $cfg['key'];
        $this->model = $model ?? $cfg['model'] ?? 'gemini/gemini-3-flash-preview';
    }

    /** @return array{base:string,key:string,model?:string} */
    public static function config(): array
    {
        $base = \getenv('OMNIROUTE_BASE');
        $key = \getenv('OMNIROUTE_API_KEY');
        $model = \getenv('OMNIROUTE_MODEL');
        if ($base && $key) {
            return ['base' => $base, 'key' => $key, 'model' => $model ?: null];
        }
        $file = \getenv('HOME') . '/.config/madeline-mcp/omniroute.json';
        if (\is_file($file)) {
            $d = \json_decode((string) \file_get_contents($file), true);
            if (\is_array($d) && !empty($d['base']) && !empty($d['key'])) {
                return ['base' => (string) $d['base'], 'key' => (string) $d['key'], 'model' => (string) ($d['model'] ?? '') ?: null];
            }
        }
        throw new RuntimeException('OmniRoute config missing: set OMNIROUTE_BASE/OMNIROUTE_API_KEY or ~/.config/madeline-mcp/omniroute.json');
    }

    /**
     * One chat completion; returns the assistant message array
     * (['role'=>'assistant','content'=>..,'tool_calls'=>..]).
     */
    public function chat(array $messages, ?array $tools = null, int $timeout = 120): array
    {
        $body = ['model' => $this->model, 'messages' => \array_values($messages)];
        if ($tools !== null && $tools !== []) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }
        [$status, $raw] = $this->post('/chat/completions', $body, $timeout);
        $d = \json_decode((string) $raw, true);
        if (!\is_array($d)) {
            throw new RuntimeException("OmniRoute HTTP $status: non-JSON reply");
        }
        if (isset($d['error'])) {
            throw new RuntimeException('OmniRoute error: ' . (\is_array($d['error']) ? ($d['error']['message'] ?? 'unknown') : (string) $d['error']));
        }
        $msg = $d['choices'][0]['message'] ?? null;
        if (!\is_array($msg)) {
            throw new RuntimeException("OmniRoute HTTP $status: no choice message");
        }
        return $msg;
    }

    /** @return array{0:int,1:string} */
    private function post(string $path, array $body, int $timeout): array
    {
        $ch = \curl_init($this->base . $path);
        \curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->key,
            ],
            CURLOPT_POSTFIELDS => \json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $raw = \curl_exec($ch);
        $err = \curl_error($ch);
        $status = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        \curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('OmniRoute request failed: ' . $err);
        }
        return [$status, (string) $raw];
    }
}
