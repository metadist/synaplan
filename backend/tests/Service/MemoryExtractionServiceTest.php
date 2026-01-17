<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MemoryExtractionService;
use App\Service\UserMemoryService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Unit tests for MemoryExtractionService.
 * Tests memory extraction logic through public interface.
 */
class MemoryExtractionServiceTest extends KernelTestCase
{
    private MemoryExtractionService $service;
    private UserMemoryService $userMemoryService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->userMemoryService = $this->createMock(UserMemoryService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MemoryExtractionService(
            $this->userMemoryService,
            $this->logger
        );
    }

    public function testExtractAndStoreMemoriesSkipsIfNoContent(): void
    {
        $this->userMemoryService
            ->expects($this->never())
            ->method('createMemory');

        $this->service->extractAndStoreMemories(1, '', null);
    }

    public function testExtractAndStoreMemoriesSkipsIfContentTooShort(): void
    {
        $this->userMemoryService
            ->expects($this->never())
            ->method('createMemory');

        // Very short message that fails heuristic filter
        $this->service->extractAndStoreMemories(1, 'Hi', null);
        $this->service->extractAndStoreMemories(1, 'OK', null);
    }

    public function testExtractAndStoreMemoriesSkipsGenericQuestions(): void
    {
        $this->userMemoryService
            ->expects($this->never())
            ->method('createMemory');

        // Generic questions without personal context
        $this->service->extractAndStoreMemories(1, 'What is TypeScript?', null);
        $this->service->extractAndStoreMemories(1, 'How do I install Vue?', null);
    }

    public function testExtractAndStoreMemoriesSkipsGenericStatements(): void
    {
        $this->userMemoryService
            ->expects($this->never())
            ->method('createMemory');

        // Generic statements without personal pronouns
        $this->service->extractAndStoreMemories(1, 'TypeScript is a programming language', null);
        $this->service->extractAndStoreMemories(1, 'The weather is nice', null);
    }

    public function testExtractAndStoreMemoriesLogsErrorsForValidContent(): void
    {
        // Valid content with personal context should pass heuristic
        // but will fail without real AI provider
        $this->logger
            ->expects($this->once())
            ->method('error');

        // This passes heuristic filter but fails AI extraction
        $this->service->extractAndStoreMemories(1, 'I prefer TypeScript for web development', 123);
    }

    public function testExtractAndStoreMemoriesHandlesNullMessageId(): void
    {
        // Should not crash with null messageId
        $this->logger
            ->expects($this->once())
            ->method('error');

        $this->service->extractAndStoreMemories(1, 'I prefer TypeScript', null);
    }
}
