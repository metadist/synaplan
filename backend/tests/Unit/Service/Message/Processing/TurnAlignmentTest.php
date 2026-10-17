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

    /** @var list<int> history size per classifier call */
    private array $classifierCalls = [];

    /** @var list<array{classification: array<string, mixed>, options: array<string, mixed>}> */
    private array $routed = [];

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

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $verdict
     *
     * @return array{classification: array<string, mixed>, options: array<string, mixed>}
     */
    private function buffered(array $options, array $verdict = [], string $language = 'en'): array
    {
        $this->processor($verdict)->process($this->message($language), $options);

        return $this->lastRouted();
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $verdict
     *
     * @return array{classification: array<string, mixed>, options: array<string, mixed>}
     */
    private function streamed(array $options, array $verdict = [], string $language = 'en'): array
    {
        $this->processor($verdict)->processStream($this->message($language), static function (): void {}, null, $options);

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
        $prompts->method('getPromptWithMetadata')->willReturn(['prompt' => null, 'metadata' => ['aiModel' => 0]]);

        $summaries = $this->createStub(ConversationSummaryService::class);
        $summaries->method('buildRollingContext')->willReturnCallback(static fn (array $window): RollingSummaryResult => RollingSummaryResult::notApplied($window));

        $urls = $this->createStub(UrlContentService::class);
        $urls->method('extractUrls')->willReturn([]);

        return MessageProcessorFactory::create(
            $messages,
            null,
            $preProcessor,
            $classifier,
            $router,
            $this->createStub(ModelConfigService::class),
            $prompts,
            WebSearchGatewayFactory::fromBrave($this->createStub(BraveSearchService::class)),
            $this->createStub(SearchQueryGenerator::class),
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

    private function message(string $language): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn(101);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn('Hello, how are you?');
        $message->method('getLanguage')->willReturn($language);
        $message->method('getTopic')->willReturn('general');
        $message->method('getChatId')->willReturn(5);

        return $message;
    }
}
