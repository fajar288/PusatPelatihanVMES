<?php

namespace Tests\Fakes;

use App\Support\WhatsApp\WhatsAppSender;
use RuntimeException;

/** Pengganti WhatsApp di tes: mencatat pesan; `$failures` = jumlah percobaan pertama yang dibuat gagal. */
class FakeWhatsAppSender implements WhatsAppSender
{
    /** @var list<array{to: string, text: string, id: string}> */
    public array $sent = [];

    public int $failures = 0;

    public function __construct(public bool $on = true) {}

    public function enabled(): bool
    {
        return $this->on;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function send(string $to, string $text): string
    {
        if ($this->failures > 0) {
            $this->failures--;

            throw new RuntimeException('Penyedia WA tidak merespons (simulasi)');
        }
        $id = 'wamid-'.(count($this->sent) + 1);
        $this->sent[] = ['to' => $to, 'text' => $text, 'id' => $id];

        return $id;
    }
}
