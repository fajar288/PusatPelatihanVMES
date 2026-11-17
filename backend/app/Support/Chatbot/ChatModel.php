<?php

namespace App\Support\Chatbot;

/**
 * Kontrak pemanggil model bahasa, dipakai bersama chatbot publik dan use case AI lain (diganti fake di tes).
 * Tanpa $options, implementasi memakai config/chatbot.php (perilaku chatbot publik tidak berubah).
 */
interface ChatModel
{
    /**
     * Opsi `json_schema` = structured output (keluaran berupa JSON sesuai skema).
     *
     * @param  list<array{type:string,text:string,cache?:bool}>  $system  blok prompt sistem; `cache` = titik cache
     * @param  list<array{role:'user'|'assistant',content:string}>  $messages
     * @param  array{model?:string,effort?:string,max_tokens?:int,timeout?:float,json_schema?:array<string,mixed>}  $options
     */
    public function reply(array $system, array $messages, array $options = []): ChatReply;
}
