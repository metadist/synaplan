<?php

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\MemoryExtractionService;
use App\Service\ModelConfigService;
use App\Service\UserMemoryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MemoryExtractionServicePromptRulesTest extends TestCase
{
    public function testAnalyzeAndExtractAllowsMultipleCreatesWithSameKeyAndUsesAtomicRulesInPrompt(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $memoryService = $this->createMock(UserMemoryService::class);
        $modelConfigService = $this->createMock(ModelConfigService::class);
        $promptRepository = $this->createMock(PromptRepository::class);
        $logger = $this->createMock(LoggerInterface::class);

        // System prompt comes from DB prompt in unit tests (no DB required, just mock)
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getPrompt')->willReturn('SYSTEM PROMPT (EN)');
        $promptRepository->method('findOneBy')->willReturn($prompt);

        // Ensure we don't rely on any configured chat model for tests.
        $modelConfigService->method('getDefaultModel')->willReturn(null);

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(123);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('User said: I eat halal and prefer low-calorie meals.');

        $aiFacade
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::callback(static function (array $messages): bool {
                    if (2 !== count($messages)) {
                        return false;
                    }

                    $system = $messages[0]['content'] ?? '';
                    $user = $messages[1]['content'] ?? '';

                    return str_contains($system, 'SYSTEM PROMPT (EN)')
                        && str_contains($user, 'RULES:')
                        && str_contains($user, 'Only save facts the user states about THEMSELVES')
                        && str_contains($user, 'One fact per memory');
                }),
                1,
                self::callback(static function (array $options): bool {
                    // Match the actual code defaults: deterministic extraction, no external model required.
                    return isset($options['temperature']) && 0.3 === $options['temperature']
                        && array_key_exists('model', $options) && null === $options['model'];
                })
            )
            ->willReturn([
                'content' => json_encode([
                    [
                        'action' => 'create',
                        'category' => 'preferences',
                        'key' => 'diet',
                        'value' => 'Eats halal',
                    ],
                    [
                        'action' => 'create',
                        'category' => 'preferences',
                        'key' => 'diet',
                        'value' => 'Prefers low-calorie meals for weight loss',
                    ],
                ], JSON_THROW_ON_ERROR),
            ]);

        $service = new MemoryExtractionService(
            $aiFacade,
            $modelConfigService,
            $promptRepository,
            $logger
        );

        $result = $service->analyzeAndExtract($message, [], []);

        self::assertCount(2, $result);
        self::assertSame('create', $result[0]['action']);
        self::assertSame('diet', $result[0]['key']);
        self::assertSame('Eats halal', $result[0]['value']);
        self::assertSame('create', $result[1]['action']);
        self::assertSame('diet', $result[1]['key']);
        self::assertSame('Prefers low-calorie meals for weight loss', $result[1]['value']);
    }
}
