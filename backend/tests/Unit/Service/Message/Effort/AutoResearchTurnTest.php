<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Effort;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Message\AttachmentSearchContextResolver;
use App\Service\Message\ConversationSummaryService;
use App\Service\Message\Effort\AutoResearchGate;
use App\Service\Message\Effort\AutoResearchText;
use App\Service\Message\Effort\AutoResearchTurn;
use App\Service\Message\Effort\GateDecision;
use App\Service\Message\InferenceRouter;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessagePreProcessor;
use App\Service\Message\MessageProcessor;
use App\Service\Message\Processing\TurnStatus;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class AutoResearchTurnTest extends TestCase
{
    public function testAStartedRunTellsTheStreamAndWritesTheNoticeInThePersonsLanguage(): void
    {
        $turn = $this->turn(GateDecision::started('rr_0123456789abcdef', 6, 'several_parts'));
        $events = [];
        $status = new TurnStatus(static function (array $update) use (&$events): void {
            $events[] = [$update['status'], $update['metadata']];
        }, new NullLogger());

        [$classification, $notice, $limit] = $turn->apply(new Message(), ['language' => 'de'], [], [], $status);

        self::assertSame('Ich sehe das für dich in mehreren Quellen nach — etwa 6 Minuten. Die Frage hat mehrere Teile.', $notice);
        self::assertNull($limit);
        self::assertSame('rr_0123456789abcdef', $classification['research_run_id']);
        self::assertSame([['research_started', ['run_id' => 'rr_0123456789abcdef', 'minutes' => 6, 'why' => 'several_parts']]], $events);
    }

    public function testALimitGivesTheSentenceTheAnswerBeginsWith(): void
    {
        $turn = $this->turn(GateDecision::heldBack(GateDecision::TOO_MANY));

        [$classification, $notice, $limit] = $turn->apply(new Message(), ['language' => 'en'], [], [], new TurnStatus(null, new NullLogger()));

        self::assertNull($notice);
        self::assertSame('too_many', $classification['effort_notice']);
        self::assertStringStartsWith('Too many researches are already going.', (string) $limit);
    }

    public function testAnOrdinaryTurnIsUntouched(): void
    {
        $turn = $this->turn(GateDecision::none());

        [$classification, $notice, $limit] = $turn->apply(new Message(), ['language' => 'en'], [], [], new TurnStatus(null, new NullLogger()));

        self::assertSame(['language' => 'en'], $classification);
        self::assertNull($notice);
        self::assertNull($limit);
    }

    public function testTheProcessorStreamsTheNoticeInsteadOfAnAnswer(): void
    {
        $router = $this->createMock(InferenceRouter::class);
        $router->expects(self::never())->method('routeStream');
        $processor = $this->processor($router, GateDecision::started('rr_0123456789abcdef', 6, 'comparison'));
        $streamed = '';

        $result = $processor->processStream($this->message(), static function (mixed $chunk) use (&$streamed): void {
            $streamed .= is_string($chunk) ? $chunk : '';
        });

        self::assertTrue($result['success']);
        self::assertSame('I am looking through several sources for this — about 6 minutes. It is a comparison.', $streamed);
        self::assertSame($streamed, $result['response']['content']);
        self::assertSame('research', $result['response']['metadata']['provider']);
        self::assertSame('rr_0123456789abcdef', $result['classification']['research_run_id']);
    }

    public function testTheProcessorPutsTheLimitSentenceBeforeTheAnswer(): void
    {
        $router = $this->createMock(InferenceRouter::class);
        $router->expects(self::once())->method('routeStream')->willReturnCallback(
            static function (Message $message, array $thread, array $classification, callable $stream): array {
                $stream('The answer.');

                return ['metadata' => ['provider' => 'test', 'model' => 'test']];
            },
        );
        $processor = $this->processor($router, GateDecision::heldBack(GateDecision::NO_SOURCES));
        $streamed = '';

        $result = $processor->processStream($this->message(), static function (mixed $chunk) use (&$streamed): void {
            $streamed .= is_string($chunk) ? $chunk : '';
        });

        self::assertTrue($result['success']);
        self::assertStringStartsWith('I would normally look this up in several sources', $streamed);
        self::assertStringEndsWith("\n\nThe answer.", $streamed);
        self::assertSame('no_sources', $result['classification']['effort_notice']);
    }

    private function turn(GateDecision $decision): AutoResearchTurn
    {
        $gate = $this->createStub(AutoResearchGate::class);
        $gate->method('decide')->willReturn($decision);

        return new AutoResearchTurn($gate, new AutoResearchText(self::translator()));
    }

    private function message(): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        return $message;
    }

    private function processor(InferenceRouter $router, GateDecision $decision): MessageProcessor
    {
        $preProcessor = $this->createMock(MessagePreProcessor::class);
        $preProcessor->method('process')->willReturnArgument(0);
        $messages = $this->createMock(MessageRepository::class);
        $messages->method('findConversationHistory')->willReturn([]);
        $models = $this->createMock(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(null);
        $classifier = $this->createMock(MessageClassifier::class);
        $classifier->method('classify')->willReturn(['topic' => 'CHAT', 'language' => 'en', 'source' => 'ai_sorting']);

        return MessageProcessorFactory::create(
            $messages,
            $this->createMock(SearchResultRepository::class),
            $preProcessor,
            $classifier,
            $router,
            $models,
            $this->createMock(PromptService::class),
            WebSearchGatewayFactory::fromBrave($this->createMock(BraveSearchService::class)),
            $this->createMock(SearchQueryGenerator::class),
            $this->createMock(AttachmentSearchContextResolver::class),
            $this->createMock(UrlContentService::class),
            new NullLogger(),
            $this->createMock(MultitaskRoutingConfig::class),
            $this->createMock(TaskPlanner::class),
            $this->createMock(TaskPlanStore::class),
            $this->createMock(TaskPlanExecutor::class),
            $this->createMock(ConversationSummaryService::class),
            $this->createMock(AgentConfig::class),
            autoResearch: $this->turn($decision),
        );
    }

    private static function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'de'] as $locale) {
            $translator->addResource('yaml', dirname(__DIR__, 5).'/translations/research.'.$locale.'.yaml', $locale, 'research');
        }

        return $translator;
    }
}
