<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget\Turn;

use App\AI\Exception\ChatFailureReason;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\Chat\Run\ChatRunService;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ChatErrorView;
use App\Service\Message\MessageProcessor;
use App\Service\Notification\DiscordNotificationService;
use App\Service\Widget\Turn\StoredVisitorMessage;
use App\Service\Widget\Turn\WidgetAnswerStore;
use App\Service\Widget\Turn\WidgetReply;
use App\Service\Widget\Turn\WidgetTurn;
use App\Service\Widget\WidgetApiContext;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WidgetReplyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    /** @var list<mixed> */
    private array $steps = [];

    public function testTheProcessorGetsTheWidgetsPromptModelKnowledgeBaseAndLiveData(): void
    {
        $this->frames($this->reply(), ['aiModelId' => '9', 'ragResultLimit' => 500, 'ragMinScore' => 2]);

        self::assertSame([
            'api_context' => 'LIVE DATA',
            'channel' => 'WIDGET',
            'disable_memories' => true,
            'fixed_task_prompt' => 'tools:widget-default',
            'is_widget_mode' => true,
            'rag_group_key' => 'WIDGET:wdg_1',
            'rag_limit' => 50,
            'rag_min_score' => 1.0,
            'skipSorting' => true,
            'widget_id' => 'wdg_1',
            'widget_model_id' => 9,
        ], $this->options);
    }

    public function testWithoutAModelOrLimitsTheDefaultsApply(): void
    {
        $this->frames($this->reply(), ['aiModelId' => 0, 'ragResultLimit' => 0]);

        self::assertSame([null, 1, 0.2], [$this->options['widget_model_id'], $this->options['rag_limit'], $this->options['rag_min_score']]);
    }

    public function testAnAnswerStreamsItsPiecesIsStoredAndCompletes(): void
    {
        $result = ['success' => true, 'classification' => ['topic' => 'general'], 'response' => ['metadata' => ['rag_sources' => [['n' => 1]]]]];
        $frames = $this->frames($this->reply(chunks: ['Hel', ['type' => 'reasoning', 'content' => 'Hm'], 'lo', ['type' => 'finish', 'finish_reason' => 'length']], result: $result));

        self::assertSame(['data', 'data', 'data', 'complete'], array_column($frames, 'status'));
        self::assertSame(['Hel', '<think>Hm</think>', 'lo'], array_column(array_slice($frames, 0, 3), 'chunk'));
        self::assertSame([['n' => 1]], $frames[3]['ragSources']);
        self::assertTrue($frames[3]['truncated']);
        self::assertSame([['store', 'Hel<think>Hm</think>lo']], $this->steps);
    }

    public function testAFailedAnswerMarksTheMessageAndEndsInOneErrorFrame(): void
    {
        $message = new Message();
        $frames = $this->frames($this->reply(result: ['success' => false, 'classification' => ['language' => 'de']]), message: $message);

        self::assertSame([['status' => 'error', 'error' => 'Bitte erneut versuchen.', 'errorReason' => 'schema_mismatch', 'canRetryModel' => true]], $frames);
        self::assertSame('failed', $message->getStatus());
        self::assertSame([['present', 'de'], ['flush']], $this->steps);
    }

    public function testABrokenStreamFailsTheMessageAndTakesItsCountBack(): void
    {
        $message = new Message();
        $frames = $this->frames($this->reply(chunks: ['partial'], throw: true), message: $message);

        self::assertSame([['status' => 'data', 'chunk' => 'partial'], ['status' => 'error', 'error' => 'Failed to process message']], $frames);
        self::assertSame('failed', $message->getStatus());
        self::assertSame([['decrement', false], ['flush']], $this->steps);
    }

    public function testAFailureBeforeTheStreamIsReportedToDiscord(): void
    {
        $answer = $this->reply()->failure($this->turn([]), new \RuntimeException('Database is down'));

        self::assertSame(['error' => 'Failed to process message'], $answer);
        self::assertSame([['discord', 'wdg_1', 'Database is down', 'sess']], $this->steps);
    }

    /**
     * @param list<mixed>               $chunks
     * @param array<string, mixed>|null $result
     */
    private function reply(array $chunks = [], ?array $result = null, bool $throw = false): WidgetReply
    {
        $processor = $this->createStub(MessageProcessor::class);
        $processor->method('processStream')->willReturnCallback(
            function (Message $message, callable $stream, ?callable $status = null, array $options = []) use ($chunks, $result, $throw): array {
                ksort($options);
                $this->options = $options;
                foreach ($chunks as $chunk) {
                    $stream($chunk);
                }
                if ($throw) {
                    throw new \RuntimeException('Processor failed');
                }

                return $result ?? ['success' => true, 'response' => ['metadata' => []]];
            },
        );

        $errors = $this->createStub(ChatErrorPresenter::class);
        $errors->method('presentFromResult')->willReturnCallback(function (array $result, string $language): ChatErrorView {
            $this->steps[] = ['present', $language];

            return new ChatErrorView(ChatFailureReason::SchemaMismatch, 'Bitte erneut versuchen.', null, true, 'raw');
        });
        $apiContext = $this->createStub(WidgetApiContext::class);
        $apiContext->method('fetch')->willReturn('LIVE DATA');
        $answers = $this->createStub(WidgetAnswerStore::class);
        $answers->method('store')->willReturnCallback(function (WidgetTurn $turn, StoredVisitorMessage $stored, string $answer): void {
            $this->steps[] = ['store', $answer];
        });
        $sessions = $this->createStub(WidgetSessionService::class);
        $sessions->method('decrementMessageCount')->willReturnCallback(function (WidgetSession $session, bool $flush): void {
            $this->steps[] = ['decrement', $flush];
        });
        $discord = $this->createStub(DiscordNotificationService::class);
        $discord->method('notifyWidgetError')->willReturnCallback(function (string $widgetId, string $error, array $metadata): void {
            $this->steps[] = ['discord', $widgetId, $error, $metadata['session_id']];
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(function (): void {
            $this->steps[] = ['flush'];
        });

        return new WidgetReply($processor, $this->createStub(ChatRunService::class), $errors, $apiContext, $answers, $sessions, $discord, $em, new NullLogger());
    }

    /**
     * Runs the reply and reads its frames, catching every flush the way the
     * test client does.
     *
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>
     */
    private function frames(WidgetReply $reply, array $config = [], ?Message $message = null): array
    {
        $response = $reply->stream($this->turn($config), new StoredVisitorMessage(new Chat(), $message ?? new Message(), []));

        $output = '';
        ob_start(static function (string $chunk) use (&$output): string {
            $output .= $chunk;

            return '';
        });
        try {
            $response->sendContent();
        } finally {
            ob_end_flush();
        }

        $frames = [];
        foreach (preg_split("/\n\n+/", trim($output)) ?: [] as $frame) {
            if (str_starts_with($frame, 'data: ')) {
                $frames[] = json_decode(substr($frame, 6), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function turn(array $config): WidgetTurn
    {
        $widget = (new Widget())->setWidgetId('wdg_1')->setTaskPromptTopic('tools:widget-default');
        $session = (new WidgetSession())->setWidgetId('wdg_1')->setSessionId('sess');

        return new WidgetTurn($widget, $this->createStub(User::class), $session, $config, 'Hi', [], null, '', false);
    }
}
