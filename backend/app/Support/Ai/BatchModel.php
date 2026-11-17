<?php

namespace App\Support\Ai;

use App\Support\Chatbot\ChatReply;

/**
 * Kontrak Message Batches (klasifikasi massal, setengah harga, hasil asinkron). Dipantau perintah terjadwal karena
 * cPanel tidak punya worker permanen. Diganti Tests\Fakes\FakeBatchModel di tes.
 */
interface BatchModel
{
    /**
     * @param  list<array{custom_id: string, system: list<array{type:string,text:string,cache?:bool}>, messages: list<array{role:string,content:string}>, options: array<string, mixed>}>  $requests
     * @return string ID batch di penyedia
     */
    public function submit(array $requests): string;

    /** @return string in_progress | canceling | ended */
    public function status(string $batchId): string;

    /**
     * Hasil per request; status: succeeded | errored | canceled | expired.
     *
     * @return iterable<array{custom_id: string, status: string, reply: ?ChatReply, error: ?string}>
     */
    public function results(string $batchId): iterable;
}
