<?php

namespace App\Tests\Unit;

use App\Service\PromptService;
use App\Repository\PromptRepository;
use App\Entity\Prompt;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PromptServiceTest extends TestCase
{
    private PromptRepository $promptRepository;
    private LoggerInterface $logger;
    private PromptService $service;

    protected function setUp(): void
    {
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new PromptService(
            $this->promptRepository,
            $this->logger
        );
    }

    public function testGetPromptReturnsPromptWhenFound(): void
    {
        $topic = 'general';
        $language = 'en';
        $userId = 1;

        $promptEntity = $this->createMock(Prompt::class);
        $promptEntity->method('getId')->willReturn(10);
        $promptEntity->method('getTopic')->willReturn($topic);
        $promptEntity->method('getPrompt')->willReturn('You are a helpful assistant.');
        $promptEntity->method('getLanguage')->willReturn($language);
        $promptEntity->method('getShortDescription')->willReturn('General prompt');

        $this->promptRepository
            ->expects($this->once())
            ->method('findByTopicAndUser')
            ->with($topic, $language, $userId)
            ->willReturn($promptEntity);

        $result = $this->service->getPrompt($topic, $language, $userId);

        $this->assertEquals(10, $result['BID']);
        $this->assertEquals($topic, $result['BTOPIC']);
        $this->assertEquals('You are a helpful assistant.', $result['BPROMPT']);
        $this->assertEquals($language, $result['BLANG']);
        $this->assertEquals('General prompt', $result['BSHORTDESC']);
    }

    public function testGetPromptReturnsFallbackWhenNotFound(): void
    {
        $topic = 'nonexistent';
        $language = 'de';
        $userId = 5;

        $this->promptRepository
            ->method('findByTopicAndUser')
            ->willReturn(null);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Prompt not found, using fallback', $this->arrayHasKey('topic'));

        $result = $this->service->getPrompt($topic, $language, $userId);

        $this->assertEquals(0, $result['BID']);
        $this->assertEquals($topic, $result['BTOPIC']);
        $this->assertEquals('en', $result['BLANG']);
        $this->assertStringContainsString('helpful AI assistant', $result['BPROMPT']);
    }

    public function testGetPromptWithDifferentLanguages(): void
    {
        $topic = 'tools:enhance';
        $languages = ['en', 'de', 'fr'];

        foreach ($languages as $language) {
            $promptEntity = $this->createMock(Prompt::class);
            $promptEntity->method('getId')->willReturn(20);
            $promptEntity->method('getTopic')->willReturn($topic);
            $promptEntity->method('getPrompt')->willReturn("Prompt in $language");
            $promptEntity->method('getLanguage')->willReturn($language);
            $promptEntity->method('getShortDescription')->willReturn("Description in $language");

            // Use willReturnMap to handle multiple calls with different parameters
            $this->promptRepository
                ->expects($this->once())
                ->method('findByTopicAndUser')
                ->with($topic, $language, 0)
                ->willReturn($promptEntity);

            // Create new service for each iteration
            $service = new PromptService($this->promptRepository, $this->logger);
            $result = $service->getPrompt($topic, $language, 0);

            $this->assertEquals($language, $result['BLANG']);
            $this->assertEquals("Prompt in $language", $result['BPROMPT']);

            // Recreate mocks for next iteration
            $this->setUp();
        }
    }

    public function testGetAllPromptsReturnsUserAccessiblePrompts(): void
    {
        $userId = 1;

        $prompt1 = $this->createMock(Prompt::class);
        $prompt1->method('getId')->willReturn(1);
        $prompt1->method('getTopic')->willReturn('general');
        $prompt1->method('getPrompt')->willReturn('General prompt');
        $prompt1->method('getLanguage')->willReturn('en');
        $prompt1->method('getShortDescription')->willReturn('General');

        $prompt2 = $this->createMock(Prompt::class);
        $prompt2->method('getId')->willReturn(2);
        $prompt2->method('getTopic')->willReturn('creative');
        $prompt2->method('getPrompt')->willReturn('Creative prompt');
        $prompt2->method('getLanguage')->willReturn('en');
        $prompt2->method('getShortDescription')->willReturn('Creative');

        $this->promptRepository
            ->expects($this->once())
            ->method('findAllForUser')
            ->with($userId)
            ->willReturn([$prompt1, $prompt2]);

        $result = $this->service->getAllPrompts($userId);

        $this->assertCount(2, $result);
        $this->assertEquals('general', $result[0]['BTOPIC']);
        $this->assertEquals('creative', $result[1]['BTOPIC']);
    }

    public function testGetAllPromptsDeduplicatesTopics(): void
    {
        $userId = 1;

        // User-specific prompt (should take precedence)
        $userPrompt = $this->createMock(Prompt::class);
        $userPrompt->method('getId')->willReturn(100);
        $userPrompt->method('getTopic')->willReturn('general');
        $userPrompt->method('getPrompt')->willReturn('User-specific general prompt');
        $userPrompt->method('getLanguage')->willReturn('en');
        $userPrompt->method('getShortDescription')->willReturn('User General');

        // Global prompt (should be skipped)
        $globalPrompt = $this->createMock(Prompt::class);
        $globalPrompt->method('getId')->willReturn(1);
        $globalPrompt->method('getTopic')->willReturn('general');
        $globalPrompt->method('getPrompt')->willReturn('Global general prompt');
        $globalPrompt->method('getLanguage')->willReturn('en');
        $globalPrompt->method('getShortDescription')->willReturn('Global General');

        $this->promptRepository
            ->method('findAllForUser')
            ->willReturn([$userPrompt, $globalPrompt]);

        $result = $this->service->getAllPrompts($userId);

        // Should only have one 'general' topic (the first one = user-specific)
        $this->assertCount(1, $result);
        $this->assertEquals('general', $result[0]['BTOPIC']);
        $this->assertEquals(100, $result[0]['BID']); // User-specific ID
    }

    public function testGetAllPromptsReturnsEmptyArrayWhenNoPrompts(): void
    {
        $userId = 999;

        $this->promptRepository
            ->method('findAllForUser')
            ->with($userId)
            ->willReturn([]);

        $result = $this->service->getAllPrompts($userId);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetPromptDefaultsLanguageToEn(): void
    {
        $topic = 'test';
        
        $promptEntity = $this->createMock(Prompt::class);
        $promptEntity->method('getId')->willReturn(1);
        $promptEntity->method('getTopic')->willReturn($topic);
        $promptEntity->method('getPrompt')->willReturn('Test prompt');
        $promptEntity->method('getLanguage')->willReturn('en');
        $promptEntity->method('getShortDescription')->willReturn('Test');

        $this->promptRepository
            ->expects($this->once())
            ->method('findByTopicAndUser')
            ->with($topic, 'en', 0) // default language and userId
            ->willReturn($promptEntity);

        $result = $this->service->getPrompt($topic);

        $this->assertEquals('en', $result['BLANG']);
    }

    public function testGetPromptDefaultsUserIdToZero(): void
    {
        $topic = 'test';
        $language = 'de';
        
        $promptEntity = $this->createMock(Prompt::class);
        $promptEntity->method('getId')->willReturn(1);
        $promptEntity->method('getTopic')->willReturn($topic);
        $promptEntity->method('getPrompt')->willReturn('Test prompt');
        $promptEntity->method('getLanguage')->willReturn($language);
        $promptEntity->method('getShortDescription')->willReturn('Test');

        $this->promptRepository
            ->expects($this->once())
            ->method('findByTopicAndUser')
            ->with($topic, $language, 0) // default userId = 0
            ->willReturn($promptEntity);

        $result = $this->service->getPrompt($topic, $language); // No userId provided

        $this->assertIsArray($result);
    }
}

