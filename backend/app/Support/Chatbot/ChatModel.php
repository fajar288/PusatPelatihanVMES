<?php

namespace App\Support\Chatbot;

/** Pemanggil model bahasa untuk chatbot (diganti fake di tes). */
interface ChatModel
{
    /**
     * @param  list<array{type:string,text:string,cache?:bool}>  $system  blok prompt sistem; `cache` = titik cache
     * @param  list<array{role:'user'|'assistant',content:string}>  $messages
     */
    public function reply(array $system, array $messages): ChatReply;
}
