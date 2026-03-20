<?php

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Exception\ProviderException;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use PHPUnit\Framework\TestCase;

class MediaErrorMessageBuilderTest extends TestCase
{
    private MediaErrorMessageBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MediaErrorMessageBuilder();
    }

    public function testBuildErrorMessageWithProviderExceptionAndBlockReason(): void
    {
        $exception = ProviderException::contentBlocked('google', 'SAFETY', 'Some text response');

        $message = $this->builder->buildErrorMessage($exception, 'image', 'en');

        $this->assertStringContainsString('Google refused to generate the image with code **SAFETY**.', $message);
        $this->assertStringContainsString('This means the content violates the provider\'s safety policies.', $message);
        $this->assertStringContainsString('> Some text response', $message);
    }

    public function testBuildErrorMessageWithProviderExceptionAndNoBlockReason(): void
    {
        $exception = new ProviderException('Some other error', 'google');

        $message = $this->builder->buildErrorMessage($exception, 'image', 'en');

        $this->assertStringContainsString('Sorry, the image could not be generated right now.', $message);
    }

    public function testBuildErrorMessageWithGenericException(): void
    {
        $exception = new \Exception('Generic error');

        $message = $this->builder->buildErrorMessage($exception, 'video', 'de');

        $this->assertStringContainsString('Das Video konnte leider nicht erstellt werden.', $message);
    }

    public function testBuildContentBlockedMessageGermanGrammar(): void
    {
        $exception = ProviderException::contentBlocked('openai', 'RECITATION');

        // Image
        $message = $this->builder->buildErrorMessage($exception, 'image', 'de');
        $this->assertStringContainsString('Openai hat die Erstellung des Bildes mit dem Code **RECITATION** abgelehnt.', $message);

        // Audio
        $message = $this->builder->buildErrorMessage($exception, 'audio', 'de');
        $this->assertStringContainsString('Openai hat die Erstellung des Audios mit dem Code **RECITATION** abgelehnt.', $message);

        // Video
        $message = $this->builder->buildErrorMessage($exception, 'video', 'de');
        $this->assertStringContainsString('Openai hat die Erstellung des Videos mit dem Code **RECITATION** abgelehnt.', $message);
    }

    public function testBuildContentBlockedMessageUnknownReason(): void
    {
        $exception = ProviderException::contentBlocked('anthropic', 'UNKNOWN_REASON');

        $message = $this->builder->buildErrorMessage($exception, 'image', 'en');

        $this->assertStringContainsString('Anthropic refused to generate the image with code **UNKNOWN_REASON**.', $message);
        $this->assertStringContainsString('The request was blocked for an unknown reason.', $message);
    }
}
