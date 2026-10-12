<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Exception;

use App\AI\Exception\NoImageException;
use App\AI\Exception\ProviderException;
use PHPUnit\Framework\TestCase;

final class ProviderExceptionNoImageTest extends TestCase
{
    public function testTheMessageNamesTheProviderAndQuotesTheReply(): void
    {
        $e = ProviderException::noImage('google', 'gemini-3.1-flash-image', "  10\n", 'STOP');

        self::assertSame('Google returned text instead of an image (gemini-3.1-flash-image): "10"', $e->getMessage());
        self::assertSame('google', $e->getProviderName());
        self::assertSame(['text_response' => '10', 'finish_reason' => 'STOP', 'model' => 'gemini-3.1-flash-image'], $e->getContext());
    }

    public function testWithoutAReplyTheMessageNamesTheFinishReason(): void
    {
        $e = ProviderException::noImage('openai', 'gpt-image-1.5', '   ', null);

        self::assertSame('OpenAI returned no image (gpt-image-1.5)', $e->getMessage());
        self::assertNull($e->getContext()['text_response'] ?? null);
    }

    public function testTheLogContextCarriesProviderModelReasonAndAnExcerpt(): void
    {
        $e = ProviderException::noImage('google', 'gemini-3.1-flash-image', str_repeat('x', 400), 'STOP');

        $context = $e->logContext();

        self::assertSame('google', $context['provider']);
        self::assertSame('gemini-3.1-flash-image', $context['model']);
        self::assertSame('STOP', $context['finish_reason']);
        self::assertSame(300, mb_strlen($context['text_response']));
    }

    public function testAFilteredImageCarriesASafetyBlockReasonAndStaysOutOfTheOutageCounters(): void
    {
        $e = ProviderException::imageFiltered('google', 'imagen-4.0-generate-001', ' The image was filtered. ');

        self::assertInstanceOf(NoImageException::class, $e);
        self::assertSame('Google filtered the generated image (imagen-4.0-generate-001): "The image was filtered."', $e->getMessage());
        self::assertSame(
            ['block_reason' => 'SAFETY', 'text_response' => 'The image was filtered.', 'model' => 'imagen-4.0-generate-001'],
            $e->getContext(),
        );
    }

    public function testTheLogContextOfAPlainProviderErrorHasOnlyTheProvider(): void
    {
        self::assertSame(['provider' => 'openai'], (new ProviderException('HTTP 500', 'openai'))->logContext());
    }
}
