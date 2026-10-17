<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\Exception\ProviderException;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Exception\StreamCancelledException;
use App\Service\Message\AttachmentSearchContextResolver;
use App\Service\Message\ConversationSummaryService;
use App\Service\Message\InferenceRouter;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessagePreProcessor;
use App\Service\Message\RollingSummaryResult;
use App\Service\Message\SearchQueryGenerator;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanner;
use App\Service\Multitask\TaskPlanStore;
use App\Service\Prompt\PromptService;
use App\Service\Research\UrlContentResult;
use App\Service\Research\UrlContentService;
use App\Service\Search\BraveSearchService;
use App\Tests\Characterization\Support\CleanupSnapshot;
use App\Tests\Support\MessageProcessorFactory;
use App\Tests\Support\WebSearchGatewayFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What MessageProcessor does on both entry points, per kind of turn.
 *
 * For every case `process()` and `processStream()` each record the status
 * events, the classifier calls (and how many: each one is a paid sorter call),
 * the prompt lookups, the web search, what reaches the router
 * (classification, options, history size) and the result. The two
 * entries differ in several places today (fixed-prompt language, RAG keys,
 * where search results travel, who emits `complete`, cancellation); the
 * snapshot pins those differences until they are aligned on purpose.
 *
 * Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
#[AllowMockObjectsWithoutExpectations]
final class MessageTurnCharacterizationTest extends TestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/message_turn.json';

    private const SORTER_VERDICT = [
        'topic' => 'general',
        'language' => 'en',
        'source' => 'ai_sorting',
        'intent' => 'chat',
        'model_id' => 5,
        'provider' => 'groq',
        'model_name' => 'sorter-model',
    ];

    /** @var array<string, list<mixed>> */
    private array $calls = [];

    public function testBothEntryPointsMatchTheSnapshot(): void
    {
        $actual = [];
        foreach ($this->cases() as $name => $case) {
            $actual[$name] = [
                'buffered' => $this->runCase($case, false),
                'streamed' => $this->runCase($case, true),
            ];
        }

        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'Message turn transcript');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function cases(): array
    {
        return [
            'plain_chat' => [],
            'override_model' => ['options' => ['override_model_id' => 31]],
            'fixed_prompt_api' => ['options' => ['fixed_task_prompt' => 'support']],
            'fixed_prompt_widget' => ['options' => ['fixed_task_prompt' => 'support', 'is_widget_mode' => true, 'widget_model_id' => 77, 'rag_group_key' => 'kb']],
            'fixed_prompt_saved_task' => ['options' => ['fixed_task_prompt' => 'digest', 'saved_task' => true, 'saved_task_id' => 9]],
            'again_with_media_model' => ['options' => ['is_again' => true, 'model_id' => 42]],
            'forced_image_description' => ['options' => ['force_image_description' => true]],
            'widget_without_fixed_prompt' => ['options' => ['is_widget_mode' => true]],
            'classifier_votes_web_search' => ['verdict' => ['web_search' => true], 'text' => 'What is the latest Synaplan release?'],
            'explicit_search_request' => ['options' => ['web_search' => true], 'text' => 'Synaplan pricing'],
            'url_in_message' => ['text' => 'Summarize https://example.com/page please', 'prompt_metadata' => ['tool_url_screenshot' => true]],
            'rag_scope_and_language' => ['options' => ['rag_group_key' => 'kb', 'rag_limit' => 4, 'rag_min_score' => 0.4, 'language' => 'de']],
            'incognito_history' => ['chat_id' => null, 'options' => ['incognito' => true, 'incognito_history' => [
                ['role' => 'user', 'content' => 'Earlier question'],
                ['role' => 'assistant', 'content' => 'Earlier answer'],
            ]]],
            'legacy_tracking_history' => ['chat_id' => null],
            'provider_error' => ['router_throws' => 'provider'],
            'generic_error' => ['router_throws' => 'generic'],
            'user_cancel' => ['router_throws' => 'cancel'],
            'fixed_prompt_message_language' => ['message_language' => 'de', 'options' => ['fixed_task_prompt' => 'support']],
            'fixed_prompt_caller_language' => ['message_language' => 'de', 'options' => ['fixed_task_prompt' => 'support', 'language' => 'fr']],
            'again_message_language' => ['message_language' => 'de', 'options' => ['is_again' => true, 'model_id' => 42]],
            'again_caller_language' => ['message_language' => 'de', 'options' => ['is_again' => true, 'model_id' => 42, 'language' => 'fr']],
            'forced_image_message_language' => ['message_language' => 'de', 'options' => ['force_image_description' => true]],
            'forced_image_caller_language' => ['message_language' => 'de', 'options' => ['force_image_description' => true, 'language' => 'fr']],
            'classifier_carries_prompt_metadata' => [
                'verdict' => ['web_search' => true, 'prompt_metadata' => ['aiModel' => 33, 'tool_internet' => false]],
                'text' => 'What is the latest Synaplan release?',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    private function runCase(array $case, bool $streamed): array
    {
        $this->calls = ['classifier' => [], 'prompt' => [], 'search' => [], 'saved_results' => [], 'router' => [], 'stream_chunks' => []];
        $statuses = [];

        $processor = MessageProcessorFactory::create(
            $this->messageRepository(),
            $this->searchResultRepository(),
            $this->preProcessor(),
            $this->classifier($case['verdict'] ?? []),
            $this->router($case['router_throws'] ?? null),
            $this->modelConfig(),
            $this->promptService($case['prompt_metadata'] ?? []),
            WebSearchGatewayFactory::fromBrave($this->brave()),
            $this->queryGenerator(),
            $this->createMock(AttachmentSearchContextResolver::class),
            $this->urlContent(),
            new NullLogger(),
            $this->createMock(MultitaskRoutingConfig::class),
            $this->createMock(TaskPlanner::class),
            $this->createMock(TaskPlanStore::class),
            $this->createMock(TaskPlanExecutor::class),
            $this->summaries(),
            $this->createMock(AgentConfig::class),
        );

        $message = $this->message(
            (string) ($case['text'] ?? 'Hello, how are you?'),
            array_key_exists('chat_id', $case) ? $case['chat_id'] : 5,
            (string) ($case['message_language'] ?? 'en'),
        );
        $options = $case['options'] ?? [];
        $status = static function (array $update) use (&$statuses): void {
            $statuses[] = ['status' => $update['status'], 'message' => $update['message'], 'metadata' => $update['metadata'] ?? null];
        };

        $result = $streamed
            ? $processor->processStream($message, function (mixed $chunk): void {
                $this->calls['stream_chunks'][] = $chunk;
            }, $status, $options)
            : $processor->process($message, $options, $status);

        return [
            'statuses' => $statuses,
            'sorter_calls' => count($this->calls['classifier']),
            'calls' => $this->calls,
            'result' => self::normalize($result),
        ];
    }

    private function message(string $text, ?int $chatId, string $language): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(101);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn($language);
        $message->method('getTopic')->willReturn('general');
        $message->method('getChatId')->willReturn($chatId);
        $message->method('getTrackingId')->willReturn(555);
        $message->method('getFile')->willReturn(0);
        $message->method('getFileText')->willReturn('');
        $message->method('hasFiles')->willReturn(false);

        return $message;
    }

    private function messageRepository(): MessageRepository
    {
        $history = $this->createMock(Message::class);
        $history->method('getId')->willReturn(100);
        $history->method('getDirection')->willReturn('IN');
        $history->method('getText')->willReturn('Earlier turn');

        $repository = $this->createMock(MessageRepository::class);
        $repository->method('findChatHistory')->willReturn([$history]);
        $repository->method('findConversationHistory')->willReturn([$history, $history]);
        $repository->method('countByChatId')->willReturn(2);

        return $repository;
    }

    private function searchResultRepository(): SearchResultRepository
    {
        $repository = $this->createMock(SearchResultRepository::class);
        $repository->method('saveSearchResults')->willReturnCallback(function (Message $message, array $results, string $query): void {
            $this->calls['saved_results'][] = ['query' => $query, 'count' => count($results['results'] ?? [])];
        });

        return $repository;
    }

    private function preProcessor(): MessagePreProcessor
    {
        $preProcessor = $this->createMock(MessagePreProcessor::class);
        $preProcessor->method('process')->willReturnArgument(0);

        return $preProcessor;
    }

    /**
     * @param array<string, mixed> $verdict
     */
    private function classifier(array $verdict): MessageClassifier
    {
        $classifier = $this->createMock(MessageClassifier::class);
        $classifier->method('classify')->willReturnCallback(function (Message $message, array $history, mixed $ignored, bool $flag, array $options) use ($verdict): array {
            $this->calls['classifier'][] = ['history' => count($history), 'options' => $options];

            return $verdict + self::SORTER_VERDICT;
        });

        return $classifier;
    }

    private function router(?string $throws): InferenceRouter
    {
        $answer = function (string $method, array $history, array $classification, array $options) use ($throws): array {
            $this->calls['router'][] = ['method' => $method, 'history' => count($history), 'classification' => self::normalize($classification), 'options' => self::normalize($options)];
            match ($throws) {
                'provider' => throw new ProviderException('Model offline', 'openai', ['install_command' => 'ollama pull x']),
                'generic' => throw new \RuntimeException('Handler crashed'),
                'cancel' => throw new StreamCancelledException('Stopped by user'),
                default => null,
            };

            return ['content' => 'Answer', 'metadata' => ['provider' => 'openai', 'model' => 'gpt-4']];
        };

        $router = $this->createMock(InferenceRouter::class);
        $router->method('route')->willReturnCallback(static fn (Message $m, array $history, array $classification, ?callable $status = null, array $options = []): array => $answer('route', $history, $classification, $options));
        $router->method('routeStream')->willReturnCallback(static function (Message $m, array $history, array $classification, callable $stream, ?callable $status = null, array $options = []) use ($answer): array {
            $response = $answer('routeStream', $history, $classification, $options);
            $stream('Answer');

            return $response;
        });

        return $router;
    }

    private function modelConfig(): ModelConfigService
    {
        $config = $this->createMock(ModelConfigService::class);
        $config->method('getDefaultModel')->willReturnCallback(static fn (string $capability): ?int => match ($capability) {
            'SORT' => 5,
            'CHAT' => 12,
            default => null,
        });
        $config->method('getProviderForModel')->willReturn('openai');
        $config->method('getModelName')->willReturnCallback(static fn (int $id): string => 'model-'.$id);
        $config->method('getModelTag')->willReturn('text2pic');
        $config->method('getEffectiveUserIdForMessage')->willReturn(7);

        return $config;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function promptService(array $metadata): PromptService
    {
        $prompts = $this->createMock(PromptService::class);
        $prompts->method('getPromptWithMetadata')->willReturnCallback(function (string $topic, ?int $userId = null, ?string $language = null) use ($metadata): array {
            $this->calls['prompt'][] = ['topic' => $topic, 'language' => $language];

            return ['prompt' => null, 'metadata' => $metadata + ['aiModel' => 0]];
        });

        return $prompts;
    }

    private function brave(): BraveSearchService
    {
        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(true);
        $brave->method('search')->willReturnCallback(function (string $query, array $options = []): array {
            $this->calls['search'][] = ['query' => $query, 'options' => $options];

            return ['results' => [
                ['title' => 'Release notes', 'url' => 'https://example.org/releases', 'description' => 'Latest release'],
                ['title' => 'Blog', 'url' => 'https://example.org/blog', 'description' => 'Announcement'],
            ]];
        });

        return $brave;
    }

    private function queryGenerator(): SearchQueryGenerator
    {
        $generator = $this->createMock(SearchQueryGenerator::class);
        $generator->method('generate')->willReturnCallback(static fn (string $text): string => 'optimized: '.$text);

        return $generator;
    }

    private function urlContent(): UrlContentService
    {
        $urls = $this->createMock(UrlContentService::class);
        $urls->method('extractUrls')->willReturnCallback(static function (string $text): array {
            preg_match_all('#https?://\S+#', $text, $matches);

            return $matches[0];
        });
        $urls->method('fetchMultiple')->willReturnCallback(static fn (array $list): array => array_map(
            static fn (string $url): UrlContentResult => new UrlContentResult($url, 'Page body', 'Page', 'example.com', true),
            $list,
        ));
        $urls->method('formatForPrompt')->willReturn('=== PAGE ===');

        return $urls;
    }

    private function summaries(): ConversationSummaryService
    {
        $summaries = $this->createMock(ConversationSummaryService::class);
        $summaries->method('buildRollingContext')->willReturnCallback(static fn (array $window): RollingSummaryResult => RollingSummaryResult::notApplied($window));

        return $summaries;
    }

    /**
     * Objects become their class name, so timers and entities compare by kind.
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            return $value::class;
        }
        if (!is_array($value)) {
            return $value;
        }
        unset($value['trace'], $value['file'], $value['line']);
        if (isset($value['details']) && is_array($value['details'])) {
            $value['details'] = array_keys($value['details']);
        }
        ksort($value);

        return array_map(self::normalize(...), $value);
    }
}
