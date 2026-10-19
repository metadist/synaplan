<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Exception\ChatFailureReason;
use App\AI\Exception\ProviderException;
use App\Service\Message\ChatErrorPresenter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Yaml\Yaml;

final class ChatErrorPresenterTest extends TestCase
{
    private ChatErrorPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new ChatErrorPresenter(new IdentityTranslator(), new ChatFailureClassifier(), $this->authorization(false));
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

    public function testADemoRefusalSaysWhatDidNotHappenAndNamesTheRecoveryForTheCaller(): void
    {
        $refusal = new ProviderException('limit', 'openaicompatible', ['error_code' => 'freeapi_limit_reached', 'status_code' => 429], 429);
        $adminPresenter = new ChatErrorPresenter(new IdentityTranslator(), new ChatFailureClassifier(), $this->authorization(true));

        $user = $this->presenter->present($refusal, 'en', false);
        // Diagnostics stay off on webhooks and image analysis; the recovery
        // still addresses the admin who can add a provider.
        $admin = $adminPresenter->present($refusal, 'en', false);
        $adminWithDiagnostics = $adminPresenter->present($refusal, 'en', true);

        self::assertSame('freeapi.limit_reached freeapi.recovery.user', $user->userText);
        self::assertSame('freeapi.limit_reached freeapi.recovery.admin', $admin->userText);
        self::assertSame('freeapi.limit_reached freeapi.recovery.admin', $adminWithDiagnostics->userText);
        self::assertFalse($user->canRetryWithOtherModel, 'another model of the same gateway is refused alike');
        self::assertNull($user->adminDetail);
        self::assertNull($admin->adminDetail);
        self::assertNotNull($adminWithDiagnostics->adminDetail);
    }

    public function testAnUnknownDemoCodeStillGetsADemoSentence(): void
    {
        $wrapped = new \RuntimeException('turn failed', 0, new ProviderException('nope', 'openaicompatible', ['error_code' => 'freeapi_invalid_key'], 401));

        self::assertSame('freeapi.unavailable freeapi.recovery.user', $this->presenter->present($wrapped, 'en', false)->userText);
    }

    public function testEveryDemoSentenceExistsInEveryLocale(): void
    {
        $keys = ['throttled', 'limit_reached', 'full_today', 'expired', 'paused', 'blocked', 'upgrade_required', 'not_included', 'unavailable'];
        foreach (['en', 'de', 'es', 'fr', 'tr'] as $locale) {
            $catalog = Yaml::parseFile(\dirname(__DIR__, 4).'/translations/ai_errors.'.$locale.'.yaml');
            foreach ($keys as $key) {
                self::assertNotEmpty($catalog['freeapi'][$key] ?? null, "ai_errors.$locale freeapi.$key");
            }
            self::assertNotEmpty($catalog['freeapi']['recovery']['admin'] ?? null, "ai_errors.$locale freeapi.recovery.admin");
            self::assertNotEmpty($catalog['freeapi']['recovery']['user'] ?? null, "ai_errors.$locale freeapi.recovery.user");
        }
    }

    private function authorization(bool $admin): AuthorizationCheckerInterface
    {
        return new class($admin) implements AuthorizationCheckerInterface {
            public function __construct(private bool $admin)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return 'ROLE_ADMIN' === $attribute && $this->admin;
            }
        };
    }
}
