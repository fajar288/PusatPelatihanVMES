<?php

namespace Tests\Fakes;

use App\Support\Ai\BatchModel;
use App\Support\Chatbot\ChatReply;
use Closure;

/** Pengganti Message Batches di tes: mencatat request, status bisa diatur, hasil dari callback per custom_id. */
class FakeBatchModel implements BatchModel
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $batches = [];

    public string $state = 'ended';

    /** @param  Closure(array<string, mixed>): (array{status?: string, text?: string, refused?: bool}|null)  $respond */
    public function __construct(public ?Closure $respond = null) {}

    public function submit(array $requests): string
    {
        $id = 'msgbatch_fake_'.(count($this->batches) + 1);
        $this->batches[$id] = $requests;

        return $id;
    }

    public function status(string $batchId): string
    {
        return $this->state;
    }

    public function results(string $batchId): iterable
    {
        foreach ($this->batches[$batchId] ?? [] as $r) {
            $out = $this->respond ? ($this->respond)($r) : null;
            $status = $out['status'] ?? 'succeeded';
            yield ['custom_id' => $r['custom_id'], 'status' => $status, 'error' => $status === 'succeeded' ? null : $status,
                'reply' => $status === 'succeeded' ? new ChatReply(text: $out['text'] ?? '{}', refused: (bool) ($out['refused'] ?? false), model: $r['options']['model'] ?? 'claude-opus-5-5',
                    inputTokens: 200, outputTokens: 30, cacheReadTokens: 1500) : null];
        }
    }
}
