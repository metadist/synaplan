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

    public function testImageAccessFailureGivesActionableMessageEnglish(): void
    {
        $exception = new ProviderException('Higgsfield API error (400): invalid_image_url', 'higgsfield', ['status_code' => 400]);

        $message = $this->builder->buildErrorMessage($exception, 'video', 'en');

        $this->assertStringContainsString('We couldn\'t open the image you linked.', $message);
        // Must NOT leak the raw provider text / status code.
        $this->assertStringNotContainsString('invalid_image_url', $message);
        $this->assertStringNotContainsString('400', $message);
        $this->assertStringNotContainsString('Higgsfield', $message);
    }

    public function testImageAccessFailureGivesActionableMessageGerman(): void
    {
        $exception = new ProviderException('could not download image from url', 'higgsfield');

        $message = $this->builder->buildErrorMessage($exception, 'video', 'de');

        $this->assertStringContainsString('verlinkte Bild konnte nicht geöffnet werden', $message);
        $this->assertStringNotContainsString('download image', $message);
    }

    public function testTimeoutFailureGivesActionableMessage(): void
    {
        $exception = new ProviderException('Higgsfield video generation timed out after 720 seconds', 'higgsfield');

        $message = $this->builder->buildErrorMessage($exception, 'video', 'en');

        $this->assertStringContainsString('took too long to create', $message);
        $this->assertStringNotContainsString('720', $message);
    }

    public function testCreditsFailureFromStatusCode(): void
    {
        $exception = new ProviderException('Higgsfield account is out of credits.', 'higgsfield', ['status_code' => 402]);

        $message = $this->builder->buildErrorMessage($exception, 'video', 'en');

        $this->assertStringContainsString('out of credits', $message);
        $this->assertStringContainsString('contact support', $message);
    }

    public function testAuthFailureFromStatusCodeDoesNotLeakDetails(): void
    {
        $exception = new ProviderException('Higgsfield authentication error (401): bad key', 'higgsfield', ['status_code' => 401]);

        $message = $this->builder->buildErrorMessage($exception, 'video', 'en');

        $this->assertStringContainsString('isn\'t set up correctly', $message);
        $this->assertStringNotContainsString('401', $message);
        $this->assertStringNotContainsString('bad key', $message);
    }

    public function testRateLimitFailure(): void
    {
        $exception = new ProviderException('Higgsfield rate limit exceeded. Please try again later.', 'higgsfield', ['status_code' => 429]);

        $message = $this->builder->buildErrorMessage($exception, 'video', 'en');

        $this->assertStringContainsString('very busy right now', $message);
    }

    public function testUnrecognisedFailureFallsBackToGeneric(): void
    {
        $exception = new \Exception('something completely opaque happened');

        $message = $this->builder->buildErrorMessage($exception, 'video', 'en');

        $this->assertStringContainsString('Sorry, the video could not be generated right now.', $message);
        $this->assertStringNotContainsString('opaque', $message);
    }

    public function testAdminDiagnosticsAppendRawCauseForAdminsOnly(): void
    {
        $exception = new ProviderException('Higgsfield API error (400): invalid_image_url', 'higgsfield', ['status_code' => 400]);

        // Regular user: clean, non-leaky message — never the raw provider text.
        $userMessage = $this->builder->buildErrorMessage($exception, 'video', 'en', false);
        $this->assertStringNotContainsString('invalid_image_url', $userMessage);
        $this->assertStringNotContainsString('Admin diagnostics', $userMessage);

        // Admin: same clean message PLUS the appended raw diagnostics block.
        $adminMessage = $this->builder->buildErrorMessage($exception, 'video', 'en', true);
        $this->assertStringContainsString('We couldn\'t open the image you linked.', $adminMessage);
        $this->assertStringContainsString('Admin diagnostics', $adminMessage);
        $this->assertStringContainsString('Provider: higgsfield', $adminMessage);
        $this->assertStringContainsString('invalid_image_url', $adminMessage);
        $this->assertStringContainsString('status_code', $adminMessage);
    }

    public function testContentFilteredVideoSurfacesGoogleReasonToUser(): void
    {
        $exception = ProviderException::contentBlocked(
            'google',
            'SAFETY',
            "Sorry, we can't create videos with real people's names or likenesses.",
        );

        $message = $this->builder->buildErrorMessage($exception, 'video', 'en');

        $this->assertStringContainsString('Google refused to generate the video with code **SAFETY**.', $message);
        // The actual provider reason is shown to the user as a quoted explanation.
        $this->assertStringContainsString("> Sorry, we can't create videos with real people's names or likenesses.", $message);
    }
}
