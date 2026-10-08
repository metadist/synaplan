<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\OpenAIProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * When the Responses API answers an image edit in prose, the assistant
 * `message` item is the only explanation the user can get (#2406).
 */
final class OpenAIProviderResponsesMessageTextTest extends TestCase
{
    public function testItReadsTheTextOfTheAssistantMessage(): void
    {
        $output = [
            ['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'role' => 'assistant', 'content' => [
                ['type' => 'output_text', 'text' => ''],
                ['type' => 'output_text', 'text' => 'Which window should get the grid?'],
            ]],
        ];

        self::assertSame('Which window should get the grid?', $this->messageText($output));
    }

    public function testWithoutAMessageItReturnsNull(): void
    {
        self::assertNull($this->messageText([['type' => 'image_generation_call', 'result' => '']]));
    }

    /**
     * @param array<mixed> $output
     */
    private function messageText(array $output): ?string
    {
        $provider = new OpenAIProvider(new NullLogger(), new MockHttpClient());
        $method = new \ReflectionMethod(OpenAIProvider::class, 'responsesMessageText');

        $text = $method->invoke($provider, $output);

        return is_string($text) ? $text : null;
    }
}
