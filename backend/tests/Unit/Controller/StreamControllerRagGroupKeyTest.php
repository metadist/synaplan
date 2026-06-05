<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\StreamController;
use App\Service\File\UserUploadPathBuilder;
use App\Service\GuestSessionService;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\MessageForwardingService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RateLimitService;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit coverage for {@see StreamController::applyRagGroupKey()}.
 *
 * PR #1036 reintroduces a knowledge-base folder picker in the chat
 * composer that forwards `ragGroupKey` into the SSE stream so RAG
 * retrieval can be scoped to a single file group. Copilot's review
 * flagged that this new API contract had no automated coverage.
 *
 * The contract pinned here:
 *   - A non-empty key from a normal (non-widget) chat is forwarded into
 *     `processingOptions['rag_group_key']`.
 *   - Widget mode must NEVER honour a caller-supplied key — an embedded
 *     widget is locked to its own configuration and cannot widen or
 *     redirect retrieval.
 *   - An empty/null key is a no-op (default, unscoped retrieval).
 */
final class StreamControllerRagGroupKeyTest extends TestCase
{
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->controller = new StreamController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AiFacade::class),
            $this->createMock(MessageProcessor::class),
            new NullLogger(),
            $this->createMock(ModelConfigService::class),
            $this->createMock(WidgetService::class),
            $this->createMock(WidgetSessionService::class),
            $this->createMock(GuestSessionService::class),
            $this->createMock(RateLimitService::class),
            '/tmp/upload',
            $this->createMock(UserUploadPathBuilder::class),
            $this->createMock(PromptService::class),
            $this->createMock(MessageForwardingService::class),
            $this->createMock(MemoryExtractionDispatcher::class),
        );
    }

    public function testForwardsKeyForNormalChat(): void
    {
        $options = $this->invoke(['existing' => true], false, 'kb-research');

        $this->assertSame('kb-research', $options['rag_group_key']);
        $this->assertTrue($options['existing'], 'existing options must be preserved');
    }

    public function testWidgetModeCannotOverrideRagGroup(): void
    {
        $options = $this->invoke([], true, 'kb-research');

        $this->assertArrayNotHasKey('rag_group_key', $options);
    }

    public function testEmptyKeyIsNoOp(): void
    {
        $this->assertArrayNotHasKey('rag_group_key', $this->invoke([], false, ''));
        $this->assertArrayNotHasKey('rag_group_key', $this->invoke([], false, null));
    }

    /**
     * @param array<string, mixed> $processingOptions
     *
     * @return array<string, mixed>
     */
    private function invoke(array $processingOptions, bool $isWidgetMode, ?string $ragGroupKey): array
    {
        $reflection = new \ReflectionMethod(StreamController::class, 'applyRagGroupKey');

        /** @var array<string, mixed> $result */
        $result = $reflection->invoke($this->controller, $processingOptions, $isWidgetMode, $ragGroupKey);

        return $result;
    }
}
