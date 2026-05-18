<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\WebhookController;
use App\Service\DiscordNotificationService;
use App\Service\EmailChatService;
use App\Service\EmailWebhookIdempotencyService;
use App\Service\InternalEmailService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit coverage for {@see WebhookController::normalizeGeneratedFileMetadata()}.
 *
 * Issue #626: Media generated through the email channel
 * (smart+keyword@synaplan.net) used to be stored without a file path on the
 * outgoing message, so the web chat history only showed the textual
 * description and not the actual image/video/audio player. The helper now
 * canonicalises whatever `metadata.file` shape the handler returns into the
 * `{path, type}` we persist on the Message row, or returns null so callers
 * leave the legacy `file=0` flag untouched.
 */
class WebhookControllerGeneratedFileMetaTest extends TestCase
{
    private WebhookController $controller;

    protected function setUp(): void
    {
        $this->controller = new WebhookController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MessageProcessor::class),
            $this->createMock(EmailWebhookIdempotencyService::class),
            $this->createMock(RateLimitService::class),
            $this->createMock(WhatsAppService::class),
            $this->createMock(EmailChatService::class),
            $this->createMock(InternalEmailService::class),
            $this->createMock(DiscordNotificationService::class),
            new NullLogger(),
            'test-verify-token',
            $this->createMock(AiFacade::class),
            $this->createMock(ModelConfigService::class),
        );
    }

    public function testNormalisesValidVideoFileMetadata(): void
    {
        $result = $this->invokeHelper([
            'path' => '/api/v1/files/uploads/13/000/00013/2026/05/42_google_1700000000.mp4',
            'type' => 'video',
        ]);

        self::assertSame([
            'path' => '/api/v1/files/uploads/13/000/00013/2026/05/42_google_1700000000.mp4',
            'type' => 'video',
        ], $result);
    }

    public function testNormalisesValidImageFileMetadata(): void
    {
        $result = $this->invokeHelper([
            'path' => '/api/v1/files/uploads/27/000/00027/2026/05/100_openai_1700000000.png',
            'type' => 'image',
        ]);

        self::assertSame([
            'path' => '/api/v1/files/uploads/27/000/00027/2026/05/100_openai_1700000000.png',
            'type' => 'image',
        ], $result);
    }

    public function testReturnsNullWhenMetadataMissing(): void
    {
        self::assertNull($this->invokeHelper(null));
    }

    public function testReturnsNullWhenMetadataIsNotAnArray(): void
    {
        self::assertNull($this->invokeHelper('not-an-array'));
        self::assertNull($this->invokeHelper(42));
    }

    public function testReturnsNullWhenPathIsEmpty(): void
    {
        // Empty path means the handler did not actually produce a servable
        // asset (e.g. provider returned a description-only result). We
        // must not flip `file=1` for a row with no real file behind it,
        // otherwise the frontend would render a broken media player.
        self::assertNull($this->invokeHelper(['path' => '', 'type' => 'video']));
        self::assertNull($this->invokeHelper(['path' => '   ', 'type' => 'video']));
        self::assertNull($this->invokeHelper(['type' => 'video']));
    }

    public function testReturnsNullWhenPathIsNotAString(): void
    {
        self::assertNull($this->invokeHelper(['path' => 123, 'type' => 'video']));
        self::assertNull($this->invokeHelper(['path' => null, 'type' => 'video']));
    }

    public function testDefaultsTypeToEmptyStringWhenMissing(): void
    {
        // Edge case: a handler might forget to set the type. We still
        // persist the path so the file is reachable; the frontend has a
        // legacy extension-based fallback to infer the type.
        $result = $this->invokeHelper(['path' => '/api/v1/files/uploads/foo.mp4']);

        self::assertSame([
            'path' => '/api/v1/files/uploads/foo.mp4',
            'type' => '',
        ], $result);
    }

    public function testTrimsWhitespaceAroundPathAndType(): void
    {
        $result = $this->invokeHelper([
            'path' => '  /api/v1/files/uploads/foo.mp4  ',
            'type' => "video\n",
        ]);

        self::assertSame([
            'path' => '/api/v1/files/uploads/foo.mp4',
            'type' => 'video',
        ], $result);
    }

    private function invokeHelper(mixed $fileMeta): ?array
    {
        $reflection = new \ReflectionMethod(WebhookController::class, 'normalizeGeneratedFileMetadata');

        return $reflection->invoke($this->controller, $fileMeta);
    }
}
