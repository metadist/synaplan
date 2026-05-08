<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\UserMemoryDTO;
use App\Entity\Message;
use App\Entity\User;
use App\Message\ExtractMemoriesCommand;
use App\MessageHandler\ExtractMemoriesCommandHandler;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\MemoryExtractionService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for issue #879 — duplicate memory entries created
 * instead of updating existing keys.
 *
 * The handler is the second of the two layered defences:
 *   1. The extractor LLM is now given the user's full memory list as
 *      context and is supposed to emit `update`. (Tested elsewhere via
 *      the prompt-rules suite.)
 *   2. Even if the model emits `create` anyway — providers in the wild
 *      do this routinely — the handler enforces dedup before writing
 *      to Qdrant. That is what this suite covers.
 */
final class ExtractMemoriesCommandHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MemoryExtractionService&MockObject $memoryExtractionService;
    private UserMemoryService&MockObject $memoryService;
    private LoggerInterface&MockObject $logger;
    private ExtractMemoriesCommandHandler $handler;
    private User $user;
    private Message $message;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->memoryExtractionService = $this->createMock(MemoryExtractionService::class);
        $this->memoryService = $this->createMock(UserMemoryService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new ExtractMemoriesCommandHandler(
            $this->em,
            $this->memoryExtractionService,
            $this->memoryService,
            $this->logger,
        );

        $this->user = $this->createConfiguredMock(User::class, [
            'getId' => 1,
            'isMemoriesEnabled' => true,
        ]);
        $this->message = $this->createConfiguredMock(Message::class, [
            'getId' => 42,
            'getUserId' => 1,
            'getTrackingId' => 7,
        ]);

        $messageRepo = $this->createMock(MessageRepository::class);
        $messageRepo->method('find')->with(42)->willReturn($this->message);
        $messageRepo->method('findOneBy')->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('find')->with(1)->willReturn($this->user);

        $this->em->method('getRepository')->willReturnMap([
            [Message::class, $messageRepo],
            [User::class, $userRepo],
        ]);
    }

    public function testExactDuplicateCreateIsDropped(): void
    {
        // The user already has `name=Furkan` (personal) stored — exactly
        // the bug from #879. The AI emits another `create` with the same
        // value, and the handler must NOT write it.
        $existing = new UserMemoryDTO(
            id: 100,
            userId: 1,
            category: 'personal',
            key: 'name',
            value: 'Furkan',
            source: 'auto_detected',
        );

        $this->memoryService->method('getUserMemories')->with(1)->willReturn([$existing]);

        $this->memoryExtractionService->method('analyzeAndExtract')->willReturn([
            ['action' => 'create', 'category' => 'personal', 'key' => 'name', 'value' => 'Furkan'],
        ]);

        $this->memoryService->expects($this->never())->method('createMemory');
        $this->memoryService->expects($this->never())->method('updateMemory');

        $this->handler->__invoke(new ExtractMemoriesCommand(
            messageId: 42,
            userId: 1,
            aiResponse: 'Hi Furkan',
            threadSnapshot: [['role' => 'user', 'content' => 'mein name ist Furkan']],
        ));
    }

    public function testCaseInsensitiveAndWhitespaceInsensitiveDuplicateIsDropped(): void
    {
        $existing = new UserMemoryDTO(
            id: 100,
            userId: 1,
            category: 'personal',
            key: 'name',
            value: 'Furkan',
            source: 'auto_detected',
        );

        $this->memoryService->method('getUserMemories')->willReturn([$existing]);

        // " FURKAN " — different casing and surrounding whitespace, but
        // semantically identical.
        $this->memoryExtractionService->method('analyzeAndExtract')->willReturn([
            ['action' => 'create', 'category' => 'PERSONAL', 'key' => 'NAME', 'value' => '  furkan  '],
        ]);

        $this->memoryService->expects($this->never())->method('createMemory');
        $this->memoryService->expects($this->never())->method('updateMemory');

        $this->handler->__invoke(new ExtractMemoriesCommand(
            messageId: 42,
            userId: 1,
            aiResponse: '',
            threadSnapshot: [],
        ));
    }

    public function testSingletonKeyWithDifferentValueIsPromotedToUpdate(): void
    {
        // The user previously stored `name=Farouk`. The new turn says the
        // user's name is actually `Furkan`. The AI fails to use the
        // `update` action; the handler must still NOT create a duplicate
        // — `name` is a singleton key.
        $existing = new UserMemoryDTO(
            id: 100,
            userId: 1,
            category: 'personal',
            key: 'name',
            value: 'Farouk',
            source: 'auto_detected',
        );

        $this->memoryService->method('getUserMemories')->willReturn([$existing]);

        $this->memoryExtractionService->method('analyzeAndExtract')->willReturn([
            ['action' => 'create', 'category' => 'personal', 'key' => 'name', 'value' => 'Furkan'],
        ]);

        $this->memoryService->expects($this->never())->method('createMemory');
        $this->memoryService
            ->expects($this->once())
            ->method('updateMemory')
            ->with(
                100,
                $this->user,
                'Furkan',
                'ai_edited',
                42,
            )
            ->willReturn(new UserMemoryDTO(
                id: 100,
                userId: 1,
                category: 'personal',
                key: 'name',
                value: 'Furkan',
                source: 'ai_edited',
            ));

        $this->handler->__invoke(new ExtractMemoriesCommand(
            messageId: 42,
            userId: 1,
            aiResponse: '',
            threadSnapshot: [],
        ));
    }

    public function testMultiValuedKeyAllowsDistinctValues(): void
    {
        // The dietary case from MemoryExtractionServicePromptRulesTest:
        // `diet` is NOT a singleton key, so two distinct values must
        // both be persisted. This guards against a too-aggressive
        // dedup that would silently lose information.
        $existing = new UserMemoryDTO(
            id: 100,
            userId: 1,
            category: 'preferences',
            key: 'diet',
            value: 'Eats halal',
            source: 'auto_detected',
        );

        $this->memoryService->method('getUserMemories')->willReturn([$existing]);

        $this->memoryExtractionService->method('analyzeAndExtract')->willReturn([
            ['action' => 'create', 'category' => 'preferences', 'key' => 'diet', 'value' => 'Prefers low-calorie meals'],
        ]);

        $this->memoryService->expects($this->never())->method('updateMemory');
        $this->memoryService
            ->expects($this->once())
            ->method('createMemory')
            ->with(
                $this->user,
                'preferences',
                'diet',
                'Prefers low-calorie meals',
                'auto_detected',
                42,
            )
            ->willReturn(new UserMemoryDTO(
                id: 200,
                userId: 1,
                category: 'preferences',
                key: 'diet',
                value: 'Prefers low-calorie meals',
                source: 'auto_detected',
            ));

        $this->handler->__invoke(new ExtractMemoriesCommand(
            messageId: 42,
            userId: 1,
            aiResponse: '',
            threadSnapshot: [],
        ));
    }

    public function testSingletonCollapseRemovesStaleSiblingsOnUpdate(): void
    {
        // Reproduces the user's screenshot from the #879 follow-up:
        //   - Pre-existing: name=Ralf  (id=100, untouched today)
        //   - Pre-existing: name=Hans  (id=200, the AI is about to update)
        // The user says "Ich bin der Hannes" → AI emits update on id=200.
        // The old `name=Ralf` is stale by definition (singleton key) and
        // must be auto-deleted.
        $ralf = new UserMemoryDTO(
            id: 100,
            userId: 1,
            category: 'personal',
            key: 'name',
            value: 'Ralf',
            source: 'auto_detected',
        );
        $hans = new UserMemoryDTO(
            id: 200,
            userId: 1,
            category: 'personal',
            key: 'name',
            value: 'Hans',
            source: 'auto_detected',
        );

        $this->memoryService->method('getUserMemories')->willReturn([$ralf, $hans]);

        $this->memoryExtractionService->method('analyzeAndExtract')->willReturn([
            ['action' => 'update', 'memory_id' => 200, 'category' => 'personal', 'key' => 'name', 'value' => 'Hannes'],
        ]);

        $this->memoryService
            ->expects($this->once())
            ->method('updateMemory')
            ->with(200, $this->user, 'Hannes', 'ai_edited', 42)
            ->willReturn(new UserMemoryDTO(
                id: 200,
                userId: 1,
                category: 'personal',
                key: 'name',
                value: 'Hannes',
                source: 'ai_edited',
            ));

        $this->memoryService
            ->expects($this->once())
            ->method('deleteMemory')
            ->with(100, $this->user);

        $this->handler->__invoke(new ExtractMemoriesCommand(
            messageId: 42,
            userId: 1,
            aiResponse: '',
            threadSnapshot: [['role' => 'user', 'content' => 'Ich bin der Hannes aus Schwerin']],
        ));
    }

    public function testSingletonCollapseRemovesStaleSiblingsOnPromotedCreate(): void
    {
        // Same scenario as above but the AI lazily emits `create` instead
        // of `update`. The handler promotes the create to an update of
        // the first match AND collapses the other sibling.
        $ralf = new UserMemoryDTO(
            id: 100,
            userId: 1,
            category: 'personal',
            key: 'name',
            value: 'Ralf',
            source: 'auto_detected',
        );
        $hans = new UserMemoryDTO(
            id: 200,
            userId: 1,
            category: 'personal',
            key: 'name',
            value: 'Hans',
            source: 'auto_detected',
        );

        $this->memoryService->method('getUserMemories')->willReturn([$ralf, $hans]);

        $this->memoryExtractionService->method('analyzeAndExtract')->willReturn([
            ['action' => 'create', 'category' => 'personal', 'key' => 'name', 'value' => 'Hannes'],
        ]);

        // Either Ralf (100) or Hans (200) gets promoted to update — we
        // don't care which one survives, only that exactly one update
        // and one delete occur and no fresh create.
        $this->memoryService
            ->expects($this->once())
            ->method('updateMemory')
            ->willReturnCallback(fn (int $id) => new UserMemoryDTO(
                id: $id,
                userId: 1,
                category: 'personal',
                key: 'name',
                value: 'Hannes',
                source: 'ai_edited',
            ));

        $this->memoryService
            ->expects($this->never())
            ->method('createMemory');

        $this->memoryService
            ->expects($this->once())
            ->method('deleteMemory');

        $this->handler->__invoke(new ExtractMemoriesCommand(
            messageId: 42,
            userId: 1,
            aiResponse: '',
            threadSnapshot: [],
        ));
    }

    public function testWithinBatchDedupAlsoApplies(): void
    {
        // No existing memory yet, but the AI emits the SAME `create`
        // twice in one extraction batch. Only the first should land.
        $this->memoryService->method('getUserMemories')->willReturn([]);

        $this->memoryExtractionService->method('analyzeAndExtract')->willReturn([
            ['action' => 'create', 'category' => 'personal', 'key' => 'name', 'value' => 'Furkan'],
            ['action' => 'create', 'category' => 'personal', 'key' => 'name', 'value' => 'Furkan'],
        ]);

        $this->memoryService
            ->expects($this->once())
            ->method('createMemory')
            ->willReturn(new UserMemoryDTO(
                id: 200,
                userId: 1,
                category: 'personal',
                key: 'name',
                value: 'Furkan',
                source: 'auto_detected',
            ));

        $this->handler->__invoke(new ExtractMemoriesCommand(
            messageId: 42,
            userId: 1,
            aiResponse: '',
            threadSnapshot: [],
        ));
    }
}
