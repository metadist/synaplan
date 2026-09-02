<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\MemoryExtractionService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit coverage for the structured-output wiring only. Full extraction
 * behaviour (existing-memory dedup, update/delete handling) has its own
 * kernel-level smoke test in {@see \App\Tests\Service\MemoryExtractionServiceTest}.
 */
final class MemoryExtractionServiceUnitTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private PromptRepository&MockObject $promptRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private MemoryExtractionService $service;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->modelConfigService->method('getMemoryModelConfig')
            ->willReturn(['model' => 'test-model', 'provider' => 'test', 'model_id' => 1]);
        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->entityManager->method('getReference')->willReturn(new User());

        $this->service = new MemoryExtractionService(
            $this->aiFacade,
            $this->modelConfigService,
            $this->createMock(RateLimitService::class),
            $this->promptRepository,
            $this->entityManager,
            new NullLogger(),
        );
    }

    public function testAnalyzeAndExtractForwardsTheMemoryExtractionSchema(): void
    {
        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"memories": []}'];
            }
        );

        $this->service->analyzeAndExtract($this->makeMessage(101, 'My name is Anna.'), []);

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('memory_extraction', $options['structured_output']->name);
    }

    /**
     * The object-wrapped schema response (`{"memories": [...]}`) must parse
     * exactly like the legacy bare-array response — no parsing change was
     * needed because the extractor's regex already grabs the innermost
     * `[...]` regardless of what wraps it.
     */
    public function testWrappedSchemaResponseParsesTheSameAsABareArray(): void
    {
        $this->aiFacade->method('chat')->willReturn([
            'content' => '{"memories": [{"action": "create", "memory_id": null, "category": "personal", "key": "name", "value": "Anna"}]}',
        ]);

        $result = $this->service->analyzeAndExtract($this->makeMessage(101, 'My name is Anna.'), []);

        self::assertSame(
            [['action' => 'create', 'category' => 'personal', 'key' => 'name', 'value' => 'Anna']],
            $result,
        );
    }

    private function makeMessage(int $id, string $text): Message
    {
        $message = new Message();
        $idProperty = new \ReflectionProperty(Message::class, 'id');
        $idProperty->setValue($message, $id);
        $message->setUserId(7);
        $message->setText($text);

        return $message;
    }
}
