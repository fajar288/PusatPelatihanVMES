<?php

namespace Tests\Unit;

use Anthropic\Client;
use App\Support\Chatbot\AnthropicChatModel;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/** Bentuk request SDK Anthropic & pembacaan respons — lewat transport HTTP palsu (tanpa jaringan / API key asli). */
class AnthropicChatModelTest extends TestCase
{
    private function transport(array $body): ClientInterface
    {
        return new class($body) implements ClientInterface
        {
            public ?RequestInterface $request = null;

            public function __construct(private array $body) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(200, ['Content-Type' => 'application/json'], json_encode($this->body));
            }
        };
    }

    private function message(array $over = []): array
    {
        return array_merge([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => 'Halo! Ada yang bisa dibantu?']],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 50, 'output_tokens' => 12, 'cache_read_input_tokens' => 2048, 'cache_creation_input_tokens' => 0],
        ], $over);
    }

    public function test_request_uses_cache_fallbacks_effort_and_reads_text(): void
    {
        config(['chatbot.model' => 'claude-opus-5-5', 'chatbot.effort' => 'low', 'chatbot.max_tokens' => 8000]);
        $http = $this->transport($this->message());
        $model = new AnthropicChatModel(new Client(apiKey: 'sk-test', requestOptions: ['transporter' => $http, 'maxRetries' => 0]));

        $r = $model->reply(
            [['type' => 'text', 'text' => 'Instruksi'], ['type' => 'text', 'text' => 'Katalog', 'cache' => true]],
            [['role' => 'user', 'content' => 'Halo']],
        );

        $this->assertSame('Halo! Ada yang bisa dibantu?', $r->text);
        $this->assertFalse($r->refused);
        $this->assertSame(2048, $r->cacheReadTokens);
        $this->assertSame(50, $r->inputTokens);

        $req = $http->request;
        $this->assertStringEndsWith('/v1/messages', $req->getUri()->getPath());
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $req->getHeaderLine('anthropic-beta'));
        $this->assertSame('Bearer sk-test', $req->getHeaderLine('Authorization'));
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame(8000, $body['max_tokens']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertArrayNotHasKey('cache_control', $body['system'][0]);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][1]['cache_control']);
        $this->assertSame([['role' => 'user', 'content' => 'Halo']], $body['messages']);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function test_refusal_is_reported(): void
    {
        $http = $this->transport($this->message(['content' => [], 'stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null]]));
        $model = new AnthropicChatModel(new Client(apiKey: 'sk-test', requestOptions: ['transporter' => $http, 'maxRetries' => 0]));

        $r = $model->reply([['type' => 'text', 'text' => 'x', 'cache' => true]], [['role' => 'user', 'content' => 'y']]);
        $this->assertTrue($r->refused);
        $this->assertSame('', $r->text);
    }
}
