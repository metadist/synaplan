<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Processing;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Message\AttachmentSearchContextResolver;
use App\Service\Message\ConversationSummaryService;
use App\Service\Message\InferenceRouter;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessagePreProcessor;
use App\Service\Message\MessageProcessor;
use App\Service\Message\RollingSummaryResult;
use App\Service\Message\SearchQueryGenerator;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanner;
use App\Service\Multitask\TaskPlanStore;
use App\Service\Prompt\PromptService;
use App\Service\Research\UrlContentService;
use App\Service\Search\BraveSearchService;
use App\Tests\Support\MessageProcessorFactory;
use App\Tests\Support\WebSearchGatewayFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The buffered and the streamed turn treat the same input the same way
 * (code cleanup V4, D1). Each test runs one turn through
 * MessageProcessor with a scripted classifier and records what reaches
 * the router.
 */
final class TurnAlignmentTest extends TestCase
{
    private const SORTER_VERDICT = ['topic' => 'general', 'language' => 'en', 'source' => 'ai_sorting', 'intent' => 'chat'];

    private const FIXED_PROMPT = ['fixed_task_prompt' => 'support'];

    private const PROMPT = ['prompt' => null, 'metadata' => ['aiModel' => 0]];

    private const SEARCH_QUESTION = 'What is the latest Synaplan release?';

    /** Turns that skip the AI sorter, so the language comes from the caller or the message. */
    private const SKIPPED_SORTER = [
        'again' => ['is_again' => true, 'model_id' => 42],
        'forced image description' => ['force_image_description' => true],
    ];

    /** @var list<int> history size per classifier call */
    private array $classifierCalls = [];

    /** @var list<array{classification: array<string, mixed>, options: array<string, mixed>}> */
    private array $routed = [];

    /** @var list<string> the language of each prompt lookup */
    private array $promptLookups = [];

    /** @var array<string, mixed> what the last turn returned to its caller */
    private array $result = [];

    /** @var array<string, mixed>|null what the web search finds; null keeps it off */
    private ?array $webResults = null;

    public function testAStreamedWidgetTurnIsClassifiedOnceWithItsHistory(): void
    {
        $this->streamed(['is_widget_mode' => true]);

        self::assertSame([1], $this->classifierCalls);
    }

    public function testBothTurnsHandTheWidgetFlagToTheHandler(): void
    {
        self::assertTrue($this->streamed(['is_widget_mode' => true])['classification']['is_widget_mode'] ?? null);
        self::assertTrue($this->buffered(['is_widget_mode' => true])['classification']['is_widget_mode'] ?? null);
    }

    public function testBothTurnsCarryTheKnowledgeBaseScopeTheCallerPicked(): void
    {
        $scope = ['rag_group_key' => 'kb', 'rag_limit' => 4, 'rag_min_score' => 0.4];

        foreach (['buffered' => $this->buffered($scope), 'streamed' => $this->streamed($scope)] as $turn => $routed) {
            self::assertSame($scope, array_intersect_key($routed['classification'], $scope), $turn);
        }
    }

    public function testTheCallersLanguageWinsOverTheMessageLanguage(): void
    {
        $this->assertTurnLanguage('fr', self::SKIPPED_SORTER, ['language' => 'fr'], 'de');
    }

    public function testTheMessageLanguageAnswersWhenTheCallerNamesNone(): void
    {
        $this->assertTurnLanguage('de', self::SKIPPED_SORTER, [], 'de');
    }

    public function testAnUnknownMessageLanguageFallsBackToEnglish(): void
    {
        $this->assertTurnLanguage('en', self::SKIPPED_SORTER, [], 'NN');
    }

    public function testAFixedPromptAnswersInTheCallersThenTheMessagesLanguageElseFollowsTheUser(): void
    {
        $fixedPrompt = ['fixed prompt' => ['fixed_task_prompt' => 'support']];

        $this->assertTurnLanguage('fr', $fixedPrompt, ['language' => 'fr'], 'de');
        $this->assertTurnLanguage('de', $fixedPrompt, [], 'de');
        $this->assertTurnLanguage('auto', $fixedPrompt, [], 'NN');
    }

    public function testBothTurnsReuseThePromptMetadataTheClassifierCarries(): void
    {
        $carried = ['verdict' => ['prompt_metadata' => ['aiModel' => 33]]];

        foreach (['buffered' => $this->buffered([], $carried['verdict']), 'streamed' => $this->streamed([], $carried['verdict'])] as $turn => $routed) {
            self::assertSame(['aiModel' => 33], $routed['classification']['prompt_metadata'] ?? null, $turn);
        }
        self::assertSame([], $this->promptLookups);
    }

    public function testBothTurnsHandTheHandlerTheLookedUpPromptInTheSameKeys(): void
    {
        foreach (['buffered' => $this->buffered(self::FIXED_PROMPT), 'streamed' => $this->streamed(self::FIXED_PROMPT)] as $turn => $routed) {
            self::assertSame(['aiModel' => 0], $routed['classification']['prompt_metadata'] ?? null, $turn);
            self::assertSame(self::PROMPT, $routed['options']['resolved_prompt_data'] ?? null, $turn);
            self::assertArrayNotHasKey('prompt_metadata', $routed['options'], $turn);
        }
    }

    public function testThePromptIsLookedUpOnceInTheTurnLanguage(): void
    {
        $this->buffered(self::FIXED_PROMPT, [], 'de');
        $this->streamed(self::FIXED_PROMPT, [], 'de');

        self::assertSame(['de', 'de'], $this->promptLookups);
    }

    public function testBothTurnsHandTheWebResultsToTheHandlerInItsOptions(): void
    {
        $this->webResults = ['results' => [['title' => 'Release notes', 'url' => 'https://example.org/releases', 'description' => 'Latest release']]];
        $vote = ['web_search' => true];

        foreach (['buffered' => $this->buffered([], $vote, 'en', self::SEARCH_QUESTION), 'streamed' => $this->streamed([], $vote, 'en', self::SEARCH_QUESTION)] as $turn => $routed) {
            self::assertSame('Release notes', $routed['options']['search_results']['results'][0]['title'] ?? null, $turn);
            self::assertArrayNotHasKey('search_results', $routed['classification'], $turn);
        }
    }

    public function testTheCallerGetsTheVerdictWithoutThePromptSettings(): void
    {
        $this->buffered(self::FIXED_PROMPT);
        self::assertArrayNotHasKey('prompt_metadata', $this->result['classification'], 'buffered');

        $this->streamed(self::FIXED_PROMPT);
        self::assertArrayNotHasKey('prompt_metadata', $this->result['classification'], 'streamed');
    }

    /**
     * @param array<string, array<string, mixed>> $kinds   options per kind of turn
     * @param array<string, mixed>                $options
     */
    private function assertTurnLanguage(string $expected, array $kinds, array $options, string $messageLanguage): void
    {
        foreach ($kinds as $kind => $kindOptions) {
            $buffered = $this->buffered($kindOptions + $options, [], $messageLanguage);
            $streamed = $this->streamed($kindOptions + $options, [], $messageLanguage);

            self::assertSame($expected, $buffered['classification']['language'], "$kind, buffered");
            self::assertSame($expected, $streamed['classification']['language'], "$kind, streamed");
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $verdict
     *
     * @return array{classification: array<string, mixed>, options: array<string, mixed>}
     */
    private function buffered(array $options, array $verdict = [], string $language = 'en', string $text = 'Hello, how are you?'): array
    {
        $this->result = $this->processor($verdict)->process($this->message($language, $text), $options);

        return $this->lastRouted();
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $verdict
     *
     * @return array{classification: array<string, mixed>, options: array<string, mixed>}
     */
    private function streamed(array $options, array $verdict = [], string $language = 'en', string $text = 'Hello, how are you?'): array
    {
        $this->result = $this->processor($verdict)->processStream($this->message($language, $text), static function (): void {}, null, $options);

        return $this->lastRouted();
    }

    /**
     * @return array{classification: array<string, mixed>, options: array<string, mixed>}
     */
    private function lastRouted(): array
    {
        self::assertNotEmpty($this->routed, 'The turn never reached the router.');

        return $this->routed[array_key_last($this->routed)];
    }

    /**
     * @param array<string, mixed> $verdict
     */
    private function processor(array $verdict): MessageProcessor
    {
        $this->routed = [];

        $classifier = $this->createStub(MessageClassifier::class);
        $classifier->method('classify')->willReturnCallback(function (Message $message, array $history) use ($verdict): array {
            $this->classifierCalls[] = count($history);

            return $verdict + self::SORTER_VERDICT;
        });

        $route = function (array $classification, array $options): array {
            $this->routed[] = ['classification' => $classification, 'options' => $options];

            return ['content' => 'Answer', 'metadata' => ['provider' => 'openai', 'model' => 'gpt-4']];
        };
        $router = $this->createStub(InferenceRouter::class);
        $router->method('route')->willReturnCallback(static fn (Message $m, array $h, array $classification, ?callable $s = null, array $options = []): array => $route($classification, $options));
        $router->method('routeStream')->willReturnCallback(static fn (Message $m, array $h, array $classification, callable $stream, ?callable $s = null, array $options = []): array => $route($classification, $options));

        $earlier = $this->createStub(Message::class);
        $earlier->method('getDirection')->willReturn('IN');
        $earlier->method('getText')->willReturn('Earlier turn');
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findChatHistory')->willReturn([$earlier]);

        $preProcessor = $this->createStub(MessagePreProcessor::class);
        $preProcessor->method('process')->willReturnArgument(0);

        $prompts = $this->createStub(PromptService::class);
        $prompts->method('getPromptWithMetadata')->willReturnCallback(function (string $topic, int $userId = 0, string $language = 'en'): array {
            $this->promptLookups[] = $language;

            return self::PROMPT;
        });

        $summaries = $this->createStub(ConversationSummaryService::class);
        $summaries->method('buildRollingContext')->willReturnCallback(static fn (array $window): RollingSummaryResult => RollingSummaryResult::notApplied($window));

        $urls = $this->createStub(UrlContentService::class);
        $urls->method('extractUrls')->willReturn([]);

        $brave = $this->createStub(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(null !== $this->webResults);
        $brave->method('search')->willReturn($this->webResults ?? []);
        $queries = $this->createStub(SearchQueryGenerator::class);
        $queries->method('generate')->willReturnArgument(0);

        return MessageProcessorFactory::create(
            $messages,
            null,
            $preProcessor,
            $classifier,
            $router,
            $this->createStub(ModelConfigService::class),
            $prompts,
            WebSearchGatewayFactory::fromBrave($brave),
            $queries,
            $this->createStub(AttachmentSearchContextResolver::class),
            $urls,
            new NullLogger(),
            $this->createStub(MultitaskRoutingConfig::class),
            $this->createStub(TaskPlanner::class),
            $this->createStub(TaskPlanStore::class),
            $this->createStub(TaskPlanExecutor::class),
            $summaries,
            $this->createStub(AgentConfig::class),
        );
    }

    private function message(string $language, string $text = 'Hello, how are you?'): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn(101);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn($language);
        $message->method('getTopic')->willReturn('general');
        $message->method('getChatId')->willReturn(5);

        return $message;
    }
}
