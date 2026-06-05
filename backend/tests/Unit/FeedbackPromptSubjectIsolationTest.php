<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Entity\Prompt;
use App\Entity\User;
use App\Prompt\PromptCatalog;
use App\Repository\PromptRepository;
use App\Service\FeedbackConfigService;
use App\Service\FeedbackContradictionService;
use App\Service\FeedbackExampleService;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\Search\BraveSearchService;
use App\Service\UserMemoryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for issue #519 — user persona (e.g. age) leaking into
 * a contradiction dialog about an external subject (the person in an
 * uploaded image).
 *
 * The bug: when the user had a stored memory `age: 32` and the AI
 * estimated a portrait subject's age, the contradiction modal's
 * "DEIN FEEDBACK" field pre-filled with "aber 32 Jahre alt" — the
 * user's own age — instead of treating the user and the portrait
 * subject as distinct.
 *
 * The fix is prompt-level: both the false-positive preview and the
 * contradiction check now carry an explicit SUBJECT-isolation rule
 * forbidding the AI from mixing personal user memories into corrections
 * or contradictions about external entities. These tests lock that rule
 * into the prompts so a future edit can't accidentally remove it.
 */
final class FeedbackPromptSubjectIsolationTest extends TestCase
{
    public function testPreviewFalsePositivePromptCarriesSubjectIsolationRule(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $modelConfigService = $this->createMock(ModelConfigService::class);
        $modelConfigService->method('getToolsModelConfig')->willReturn([
            'provider' => null,
            'model' => null,
            'model_id' => null,
        ]);

        $memoryService = $this->createMock(UserMemoryService::class);
        $memoryService->method('isAvailable')->willReturn(true);
        // Simulate the exact bug scenario: the user has stored their own
        // age, and the vector search surfaces it for an age-related claim
        // even though the claim itself is about a portrait subject.
        $memoryService->method('searchRelevantMemories')->willReturn([
            [
                'id' => 100,
                'key' => 'age',
                'value' => '32',
                'score' => 0.91,
            ],
        ]);

        $feedbackConfig = $this->createMock(FeedbackConfigService::class);
        $feedbackConfig->method('getMinContradictionScore')->willReturn(0.5);
        $feedbackConfig->method('getLimitPerNamespace')->willReturn(5);

        $capturedSystem = '';
        $capturedUser = '';
        $aiFacade
            ->expects(self::once())
            ->method('chat')
            ->with(self::callback(function (array $messages) use (&$capturedSystem, &$capturedUser): bool {
                $capturedSystem = (string) ($messages[0]['content'] ?? '');
                $capturedUser = (string) ($messages[1]['content'] ?? '');

                return true;
            }))
            // The AI's response is irrelevant to this test — we're
            // asserting on the prompt that LEAVES our process.
            ->willReturn([
                'content' => '{"classification":"feedback","summaryOptions":["x"],"correctionOptions":["y"]}',
            ]);

        $service = new FeedbackExampleService(
            $aiFacade,
            $modelConfigService,
            $this->createMock(RateLimitService::class),
            $memoryService,
            $this->createMock(VectorSearchService::class),
            $this->createMock(BraveSearchService::class),
            $this->createMock(PromptRepository::class),
            $this->createMock(LoggerInterface::class),
            $feedbackConfig,
        );

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $service->previewFalsePositive(
            $user,
            'Der Mann auf dem Foto ist zwischen 70 und 80 Jahre alt.',
            'Wie alt ist die Person auf dem Bild?'
        );

        // System prompt must instruct the AI to identify the subject
        // and refuse to substitute the user as subject for an external
        // entity.
        self::assertStringContainsString('Identify the SUBJECT', $capturedSystem);
        self::assertStringContainsString('Subject-isolation rule', $capturedSystem);
        self::assertStringContainsString(
            'MUST NEVER appear in correction options for claims about an external subject',
            $capturedSystem,
            'The system prompt must explicitly forbid bleeding personal user facts into external-subject corrections.'
        );

        // User prompt must surface the same subject-match guardrail in
        // the memory block — otherwise a strict model could still treat
        // the listed memories as the source of truth.
        self::assertStringContainsString(
            'they describe the USER, not anyone or anything else',
            $capturedUser
        );
        self::assertStringContainsString(
            'stored user memories are NOT the source',
            $capturedUser
        );
    }

    public function testContradictionCheckBatchPromptCarriesSubjectMatchRule(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $modelConfigService = $this->createMock(ModelConfigService::class);
        $modelConfigService->method('getToolsModelConfig')->willReturn([
            'provider' => null,
            'model' => null,
            'model_id' => null,
        ]);

        $memoryService = $this->createMock(UserMemoryService::class);
        $memoryService->method('isAvailable')->willReturn(true);
        $memoryService->method('searchRelevantMemories')->willReturnCallback(
            // Only the user-memory namespace returns a hit; other
            // namespaces are empty. This matches the bug scenario where
            // the only related item is the user's own age memory.
            static function (int $userId, string $query, ?string $category, int $limit, float $minScore, ?string $namespace): array {
                if (null === $namespace) {
                    return [
                        [
                            'id' => 100,
                            'key' => 'age',
                            'value' => '32',
                            'score' => 0.92,
                        ],
                    ];
                }

                return [];
            }
        );

        $feedbackConfig = $this->createMock(FeedbackConfigService::class);
        $feedbackConfig->method('getMinContradictionScore')->willReturn(0.5);
        $feedbackConfig->method('getLimitPerNamespace')->willReturn(5);

        // Use the DB-seeded system prompt so the test also locks in
        // PromptCatalog::feedbackContradictionCheckPrompt() content.
        $promptRepository = $this->createMock(PromptRepository::class);
        $systemPrompt = $this->createMock(Prompt::class);
        $systemPrompt->method('getPrompt')->willReturn(
            $this->extractContradictionPromptViaCatalogSeed()
        );
        $promptRepository->method('findOneBy')->willReturn($systemPrompt);

        $capturedSystem = '';
        $capturedUser = '';
        $aiFacade
            ->expects(self::once())
            ->method('chat')
            ->with(self::callback(function (array $messages) use (&$capturedSystem, &$capturedUser): bool {
                $capturedSystem = (string) ($messages[0]['content'] ?? '');
                $capturedUser = (string) ($messages[1]['content'] ?? '');

                return true;
            }))
            ->willReturn(['content' => '{"contradictions":[]}']);

        $service = new FeedbackContradictionService(
            $aiFacade,
            $modelConfigService,
            $this->createMock(RateLimitService::class),
            $memoryService,
            $promptRepository,
            $this->createMock(LoggerInterface::class),
            $feedbackConfig,
        );

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $service->checkContradictionsBatch(
            $user,
            'Der Mann auf dem Foto ist zwischen 70 und 80 Jahre alt.',
            'Er ist 120 Jahre alt.'
        );

        // System prompt (from PromptCatalog) must carry the rule.
        self::assertStringContainsString('Subject-match rule', $capturedSystem);
        self::assertStringContainsString(
            'always describe the USER themselves',
            $capturedSystem
        );
        self::assertStringContainsString(
            'NEVER contradicts a personal user memory',
            $capturedSystem
        );

        // Inline user prompt must restate the rule so a non-compliant
        // model still gets reminded at the point of decision.
        self::assertStringContainsString('SUBJECT-MATCH', $capturedUser);
        self::assertStringContainsString(
            'NEVER contradicts a personal user memory',
            $capturedUser
        );
    }

    /**
     * Pull the contradiction-check prompt directly from the catalog so
     * the test covers the actual seeded content, not a hand-rolled
     * fixture that could drift out of sync.
     */
    private function extractContradictionPromptViaCatalogSeed(): string
    {
        foreach (PromptCatalog::all() as $prompt) {
            if (
                'tools:feedback_contradiction_check' === $prompt['topic']
                && 'en' === $prompt['language']
            ) {
                return (string) $prompt['prompt'];
            }
        }

        self::fail('feedback_contradiction_check prompt missing from PromptCatalog::all()');
    }
}
