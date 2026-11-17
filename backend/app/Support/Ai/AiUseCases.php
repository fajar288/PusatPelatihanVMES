<?php

namespace App\Support\Ai;

use InvalidArgumentException;

/**
 * Daftar use case AI dari config/ai.php dalam bentuk yang sudah dilengkapi nilai bawaan.
 * Chatbot publik (`config: chatbot`) membaca model, effort, versi prompt, dan batas hariannya dari config/chatbot.php.
 */
final class AiUseCases
{
    public const METHOD_LLM = 'llm';

    public const METHOD_STATISTICAL = 'statistical';

    public const METHODS = [
        self::METHOD_STATISTICAL => 'Statistik (tanpa LLM)',
        self::METHOD_LLM => 'Claude (LLM)',
    ];

    public const REVIEWS = [
        'required' => 'Wajib disetujui admin',
        'staff' => 'Hanya staf, trainer & perusahaan sponsor',
        'logged' => 'Langsung tampil, tetap dicatat',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys((array) config('ai.use_cases', []));
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, (array) config('ai.use_cases', []));
    }

    /**
     * @return array{key:string,label:string,description:string,method:string,enabled:bool,independent:bool,review:string,
     *     metrics:list<string>,baseline:string,readiness:array<string,int>,model:?string,effort:?string,max_tokens:int,
     *     prompt_version:?string,daily_calls:int,daily_tokens:int}
     */
    public static function get(string $key): array
    {
        $c = config("ai.use_cases.$key");
        if (! is_array($c)) {
            throw new InvalidArgumentException("Use case AI tidak dikenal: $key");
        }
        if (($c['config'] ?? null) === 'chatbot') {
            $c += [
                'model' => config('chatbot.model'),
                'effort' => config('chatbot.effort'),
                'max_tokens' => (int) config('chatbot.max_tokens'),
                'prompt_version' => config('chatbot.prompt_version'),
                'daily_calls' => (int) config('chatbot.daily_limit'),
            ];
        }
        $llm = ($c['method'] ?? self::METHOD_STATISTICAL) === self::METHOD_LLM;

        return [
            'key' => $key,
            'label' => (string) ($c['label'] ?? $key),
            'description' => (string) ($c['description'] ?? ''),
            'method' => $llm ? self::METHOD_LLM : self::METHOD_STATISTICAL,
            'enabled' => (bool) ($c['enabled'] ?? false),
            'independent' => (bool) ($c['independent'] ?? false),
            'review' => (string) ($c['review'] ?? 'required'),
            'metrics' => array_values((array) ($c['metrics'] ?? [])),
            'baseline' => (string) ($c['baseline'] ?? ''),
            'readiness' => array_map('intval', (array) ($c['readiness'] ?? [])),
            'model' => $llm ? (string) ($c['model'] ?? 'claude-opus-5-5') : null,
            'effort' => $llm ? (string) ($c['effort'] ?? 'medium') : null,
            'max_tokens' => (int) ($c['max_tokens'] ?? 4000),
            'prompt_version' => isset($c['prompt_version']) ? (string) $c['prompt_version'] : null,
            'daily_calls' => max(0, (int) ($c['daily_calls'] ?? 0)),
            'daily_tokens' => max(0, (int) ($c['daily_tokens'] ?? 0)),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return array_map(self::get(...), self::keys());
    }
}
