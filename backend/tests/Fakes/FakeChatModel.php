<?php

namespace Tests\Fakes;

use App\Support\Chatbot\ChatModel;
use App\Support\Chatbot\ChatReply;
use RuntimeException;

/** Pengganti Claude di tes: mencatat panggilan, membalas teks tetap / menolak / melempar galat. */
class FakeChatModel implements ChatModel
{
    /** @var list<array{system: array, messages: array}> */
    public array $calls = [];

    public function __construct(public string $text = 'Jadwal CCNA tersedia, detail di /jadwal/CCNA-2611.', public bool $refuse = false, public bool $fail = false) {}

    public function reply(array $system, array $messages): ChatReply
    {
        $this->calls[] = ['system' => $system, 'messages' => $messages];
        if ($this->fail) {
            throw new RuntimeException('overloaded');
        }

        return new ChatReply(text: $this->refuse ? '' : $this->text, refused: $this->refuse, model: 'claude-opus-5-5', inputTokens: 120, outputTokens: 40, cacheReadTokens: 3000);
    }
}
