<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Exception\ChatFailureReason;
use App\AI\Exception\ProviderException;
use App\Service\Message\ChatErrorPresenter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class ChatErrorPresenterTest extends TestCase
{
    private ChatErrorPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new ChatErrorPresenter(new IdentityTranslator(), new ChatFailureClassifier());
    }

    public function testUserFacingTextDoesNotContainRawProviderMessage(): void
    {
        $raw = 'Groq chat error: Generated JSON does not match the expected schema. additionalProperties BDATETIME not allowed';
        $error = new ProviderException($raw, 'groq', [
            'error_code' => 'json_validate_failed',
            'status_code' => 400,
        ], 400);

        $view = $this->presenter->present($error, 'en', false);

        self::assertSame(ChatFailureReason::SchemaMismatch, $view->reason);
        self::assertTrue($view->canRetryWithOtherModel);
        self::assertNull($view->adminDetail);
        self::assertSame($raw, $view->rawMessage);
        self::assertStringNotContainsString('BDATETIME', $view->userText);
        self::assertStringNotContainsString('Groq chat error', $view->userText);
        self::assertStringNotContainsString($raw, $view->userText);
    }

    public function testAdminDiagnosticsIncludeProviderAndRawMessage(): void
    {
        $raw = 'Groq chat error: Generated JSON does not match the expected schema.';
        $error = new ProviderException($raw, 'groq', [
            'error_code' => 'json_validate_failed',
            'status_code' => 400,
        ], 400);

        $view = $this->presenter->present($error, 'de', true);

        self::assertNotNull($view->adminDetail);
        self::assertStringContainsString('Provider: groq', $view->adminDetail);
        self::assertStringContainsString('Status: 400', $view->adminDetail);
        self::assertStringContainsString($raw, $view->adminDetail);
        self::assertStringNotContainsString($raw, $view->userText);
    }

    public function testAuthFailureDoesNotSuggestAnotherModel(): void
    {
        $view = $this->presenter->present(
            ProviderException::missingApiKey('groq', 'GROQ_API_KEY'),
            'en',
            false,
        );

        self::assertSame(ChatFailureReason::AuthFailed, $view->reason);
        self::assertFalse($view->canRetryWithOtherModel);
    }

    public function testPresentFromResultUsesAttachedException(): void
    {
        $error = new ProviderException('down', 'openai', null, 503);
        $view = $this->presenter->presentFromResult([
            'success' => false,
            'error' => $error->getMessage(),
            'exception' => $error,
        ], 'en');

        self::assertSame(ChatFailureReason::UpstreamUnavailable, $view->reason);
    }
}
