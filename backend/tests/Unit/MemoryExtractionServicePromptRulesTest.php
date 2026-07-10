<?php

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\MemoryExtractionService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MemoryExtractionServicePromptRulesTest extends TestCase
{
    /**
     * Helper that wires a `MemoryExtractionService` with stub
     * dependencies and lets the caller inspect the exact messages
     * handed to the AI facade.
     *
     * Tests focused on prompt shaping (issue #438 et al.) don't care
     * about model selection or rate-limit recording — they just need
     * to assert that the assembled chat payload matches expectations.
     *
     * @param callable(array<int, array{role:string,content:string}>):void $assertOnMessages
     */
    private function makeServiceAndExpectChat(string $aiContent, callable $assertOnMessages): MemoryExtractionService
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade
            ->expects(self::once())
            ->method('chat')
            ->with(self::callback(function (array $messages) use ($assertOnMessages): bool {
                $assertOnMessages($messages);

                return true;
            }))
            ->willReturn(['content' => $aiContent]);

        $promptRepository = $this->createMock(PromptRepository::class);
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getPrompt')->willReturn('SYSTEM PROMPT (EN)');
        $promptRepository->method('findOneBy')->willReturn($prompt);

        $modelConfigService = $this->createMock(ModelConfigService::class);
        $modelConfigService->method('getDefaultModel')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::any())->method('getReference')
            ->with(User::class, 1)
            ->willReturn($this->createMock(User::class));

        return new MemoryExtractionService(
            $aiFacade,
            $modelConfigService,
            $this->createMock(RateLimitService::class),
            $promptRepository,
            $entityManager,
            $this->createMock(LoggerInterface::class)
        );
    }

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

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::any())->method('getReference')
            ->with(User::class, 1)
            ->willReturn($this->createMock(User::class));

        $service = new MemoryExtractionService(
            $aiFacade,
            $modelConfigService,
            $this->createMock(RateLimitService::class),
            $promptRepository,
            $entityManager,
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

    /**
     * Issue #438: the extraction LLM must never see the assistant's
     * own replies as input — they were a known leak source where the
     * model would lift a memory out of its own paraphrase instead of
     * the user's actual statement.
     */
    public function testAssistantTurnsAreStrippedFromExtractionContext(): void
    {
        $assistantBomb = 'ASSISTANT_BOMB: the user enjoys salty doner kebabs every weekend';

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(123);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Ich heisse Furkan.');

        $capturedUserPrompt = '';
        $service = $this->makeServiceAndExpectChat(
            aiContent: '[]',
            assertOnMessages: function (array $messages) use (&$capturedUserPrompt): void {
                self::assertCount(2, $messages);
                $capturedUserPrompt = (string) ($messages[1]['content'] ?? '');
            }
        );

        $service->analyzeAndExtract(
            $message,
            [
                ['role' => 'user', 'content' => 'Hi'],
                ['role' => 'assistant', 'content' => $assistantBomb],
                ['role' => 'user', 'content' => 'Wie heisst du?'],
            ],
            []
        );

        self::assertStringNotContainsString(
            $assistantBomb,
            $capturedUserPrompt,
            'Assistant-authored content must not appear in the extraction prompt.'
        );
        self::assertStringNotContainsString(
            'assistant:',
            $capturedUserPrompt,
            'The extraction prompt must not label any context line as assistant.'
        );
        self::assertStringContainsString(
            'USER turns only',
            $capturedUserPrompt,
            'The extraction prompt must declare the conversation as user-only.'
        );
        self::assertStringContainsString(
            'Never invent or extract from anything you (the assistant) wrote',
            $capturedUserPrompt,
            'The extraction prompt must explicitly forbid lifting facts from the assistant.'
        );
        // User turns must still be present so we keep useful topical context.
        self::assertStringContainsString('user: Hi', $capturedUserPrompt);
        self::assertStringContainsString('user: Wie heisst du?', $capturedUserPrompt);
    }

    /**
     * Same guarantee but for the Message-object code path: history
     * entries coming in as `App\Entity\Message` objects with
     * direction != IN are treated as assistant output and dropped.
     */
    public function testAssistantMessageObjectsAreFilteredByDirection(): void
    {
        $assistantBomb = 'BOMB_FROM_MESSAGE_OBJECT';

        $current = $this->createMock(Message::class);
        $current->method('getId')->willReturn(123);
        $current->method('getUserId')->willReturn(1);
        $current->method('getText')->willReturn('Ich heisse Furkan.');

        $userTurn = $this->createMock(Message::class);
        $userTurn->method('getDirection')->willReturn('IN');
        $userTurn->method('getText')->willReturn('Frage davor vom User');

        $assistantTurn = $this->createMock(Message::class);
        $assistantTurn->method('getDirection')->willReturn('OUT');
        $assistantTurn->method('getText')->willReturn($assistantBomb);

        $capturedUserPrompt = '';
        $service = $this->makeServiceAndExpectChat(
            aiContent: '[]',
            assertOnMessages: function (array $messages) use (&$capturedUserPrompt): void {
                $capturedUserPrompt = (string) ($messages[1]['content'] ?? '');
            }
        );

        $service->analyzeAndExtract($current, [$userTurn, $assistantTurn], []);

        self::assertStringNotContainsString($assistantBomb, $capturedUserPrompt);
        self::assertStringContainsString('user: Frage davor vom User', $capturedUserPrompt);
    }

    /**
     * Edge case: the entire history is assistant-only (a chatty UI
     * suggestion turn with no preceding user statement). The
     * extractor must still see the Current Message but get an empty
     * conversation block — never assistant lines.
     */
    public function testAllAssistantHistoryResultsInEmptyConversationBlock(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(123);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Mein Lieblingsfilm ist Inception');

        $capturedUserPrompt = '';
        $service = $this->makeServiceAndExpectChat(
            aiContent: '[]',
            assertOnMessages: function (array $messages) use (&$capturedUserPrompt): void {
                $capturedUserPrompt = (string) ($messages[1]['content'] ?? '');
            }
        );

        $service->analyzeAndExtract(
            $message,
            [
                ['role' => 'assistant', 'content' => 'Welche Filme magst du?'],
                ['role' => 'assistant', 'content' => 'Vielleicht Sci-Fi?'],
            ],
            []
        );

        self::assertStringNotContainsString('assistant:', $capturedUserPrompt);
        self::assertStringNotContainsString('Welche Filme magst du?', $capturedUserPrompt);
        // The Current Message section is unaffected by history filtering.
        self::assertStringContainsString('Mein Lieblingsfilm ist Inception', $capturedUserPrompt);
    }
}
