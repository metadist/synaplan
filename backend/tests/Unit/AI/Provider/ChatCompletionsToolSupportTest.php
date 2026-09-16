<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Provider\Concerns\ChatCompletionsToolSupport;
use App\AI\Provider\GroqProvider;
use App\AI\Provider\HuggingFaceProvider;
use App\AI\Provider\MistralProvider;
use App\AI\Provider\OpenAICompatibleProvider;
use App\AI\Provider\TrustedTokensProvider;
use App\AI\Provider\XaiProvider;
use App\AI\Tool\ToolCallAccumulator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChatCompletionsToolSupportTest extends TestCase
{
    public function testApplyAndMergeAndStreamDeltas(): void
    {
        $harness = new class {
            use ChatCompletionsToolSupport;

            public function getName(): string
            {
                return 'groq';
            }

            public function apply(array $request, array $options): array
            {
                return $this->applyChatCompletionsToolOptions($request, $options);
            }

            public function merge(array $result, array $choice): array
            {
                return $this->mergeChatCompletionsToolResult($result, $choice);
            }

            public function emit(array $choice, callable $callback): void
            {
                $this->emitChatCompletionsToolDeltas($choice, $callback);
            }
        };

        $request = $harness->apply(['model' => 'qwen/qwen3.6-27b'], [
            'tools' => [[
                'type' => 'function',
                'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object']],
            ]],
            'tool_choice' => 'auto',
            'parallel_tool_calls' => false,
        ]);
        self::assertSame('auto', $request['tool_choice']);
        self::assertFalse($request['parallel_tool_calls']);
        self::assertSame('lookup', $request['tools'][0]['function']['name']);

        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/../../../Fixtures/ai/tools/huggingface/chat_tool_calls.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR
        );
        $merged = $harness->merge(['content' => '', 'usage' => []], $fixture['choices'][0]);
        self::assertSame('tool_calls', $merged['finish_reason']);
        self::assertSame('lookup', $merged['tool_calls'][0]['function']['name']);
        self::assertSame('{"id":12}', $merged['tool_calls'][0]['function']['arguments']);

        $chunks = [];
        $sse = (string) file_get_contents(__DIR__.'/../../../Fixtures/ai/tools/huggingface/stream_tool_calls.sse');
        foreach (explode("\n", $sse) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data: ') || '[DONE]' === substr($line, 6)) {
                continue;
            }
            $json = json_decode(substr($line, 6), true);
            if (!is_array($json)) {
                continue;
            }
            $harness->emit($json['choices'][0] ?? [], static function (mixed $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            });
        }
        $acc = new ToolCallAccumulator();
        foreach ($chunks as $chunk) {
            $acc->addDelta($chunk);
        }
        $calls = $acc->complete();
        self::assertSame('lookup', $calls[0]['function']['name']);
        self::assertSame('{"id":12}', $calls[0]['function']['arguments']);
    }

    public function testCapableCatalogModelsAreFlagged(): void
    {
        $groq = new GroqProvider(new NullLogger(), 'test-key');
        self::assertInstanceOf(ToolCallingChatProviderInterface::class, $groq);
        self::assertTrue($groq->supportsToolCalling('qwen/qwen3.6-27b'));
        self::assertTrue($groq->supportsToolCalling('openai/gpt-oss-20b'));

        $mistral = new MistralProvider(
            $this->createStub(\Symfony\Contracts\HttpClient\HttpClientInterface::class),
            new NullLogger(),
            'test-key',
        );
        self::assertTrue($mistral->supportsToolCalling('mistral-large-latest'));

        $xai = new XaiProvider(
            $this->createStub(\Symfony\Contracts\HttpClient\HttpClientInterface::class),
            new NullLogger(),
            'test-key',
        );
        self::assertTrue($xai->supportsToolCalling('grok-4.6'));

        $tt = new TrustedTokensProvider(new NullLogger(), 'test-key');
        self::assertTrue($tt->supportsToolCalling('zai-org/GLM-5.2'));

        $hf = new HuggingFaceProvider(
            $this->createStub(\Symfony\Contracts\HttpClient\HttpClientInterface::class),
            new NullLogger(),
            'test-key',
        );
        self::assertTrue($hf->supportsToolCalling('moonshotai/Kimi-K2.5:deepinfra'));
    }

    public function testUnknownOpenAiCompatibleModelStaysProviderCapable(): void
    {
        $registry = $this->createStub(\App\AI\Credential\OpenAiCompatibleEndpointRegistry::class);
        $provider = new OpenAICompatibleProvider($registry, new NullLogger(), '/tmp');
        self::assertInstanceOf(ToolCallingChatProviderInterface::class, $provider);
        self::assertTrue($provider->supportsToolCalling('local-llama'));
    }
}
