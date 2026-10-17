<?php

declare(strict_types=1);

namespace App\Service\Widget\Turn;

use App\Service\Chat\Run\ChatRun;
use App\Service\Chat\Run\ChatRunService;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\MessageProcessor;
use App\Service\Notification\DiscordNotificationService;
use App\Service\Realtime\SseWriter;
use App\Service\Widget\WidgetAgentRuntime;
use App\Service\Widget\WidgetApiContext;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Last stage of a widget turn: the AI answer, streamed to the visitor as
 * server-sent events. The processor gets the widget's prompt or assistant,
 * its model, its knowledge base and live API data. The turn runs detached
 * from the connection and is recorded as a resumable run, so a visitor who
 * reloads the host page re-attaches instead of losing the answer. A failed
 * answer marks the visitor's message failed and ends in one error frame.
 */
final readonly class WidgetReply
{
    private const DEFAULT_RAG_LIMIT = 20;
    private const MAX_RAG_LIMIT = 50;
    private const DEFAULT_RAG_MIN_SCORE = 0.2;

    public function __construct(
        private MessageProcessor $processor,
        private ChatRunService $runs,
        private ChatErrorPresenter $errors,
        private WidgetApiContext $apiContext,
        private WidgetAnswerStore $answers,
        private WidgetSessionService $sessions,
        private DiscordNotificationService $discord,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ?WidgetAgentRuntime $agentRuntime = null,
    ) {
    }

    public function stream(WidgetTurn $turn, StoredVisitorMessage $stored): StreamedResponse
    {
        \set_time_limit(0);
        $options = $this->options($turn);

        return new StreamedResponse(
            function () use ($turn, $stored, $options): void {
                $this->answer($turn, $stored, $options);
            },
            200,
            [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * Reports a turn that failed before its answer started.
     *
     * @return array<string, mixed> the answer the widget reads
     */
    public function failure(WidgetTurn $turn, \Exception $e): array
    {
        $this->logger->error('Widget message failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'widget_id' => $turn->widget->getWidgetId(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        $this->discord->notifyWidgetError($turn->widget->getWidgetId(), $e->getMessage(), [
            'session_id' => $turn->session->getSessionId(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return ['error' => 'Failed to process message'];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(WidgetTurn $turn): array
    {
        $config = $turn->config;
        $widgetModelId = isset($config['aiModelId']) && (int) $config['aiModelId'] > 0 ? (int) $config['aiModelId'] : null;
        $this->logger->debug('Widget AI model resolution', [
            'widget_id' => $turn->widget->getWidgetId(),
            'configured_model_id' => $widgetModelId,
        ]);

        $options = [
            'fixed_task_prompt' => $turn->widget->getTaskPromptTopic(),
            'skipSorting' => true,
            'channel' => 'WIDGET',
            'rag_group_key' => sprintf('WIDGET:%s', $turn->widget->getWidgetId()),
            'rag_limit' => max(1, min(self::MAX_RAG_LIMIT, (int) ($config['ragResultLimit'] ?? self::DEFAULT_RAG_LIMIT))),
            'rag_min_score' => isset($config['ragMinScore']) ? max(0.0, min(1.0, (float) $config['ragMinScore'])) : self::DEFAULT_RAG_MIN_SCORE,
            'widget_id' => $turn->widget->getWidgetId(),
            'disable_memories' => true,
            'is_widget_mode' => true,
            'widget_model_id' => $widgetModelId,
            'api_context' => $this->apiContext->fetch($turn->widget, $turn->owner, $turn->externalUserId),
        ];

        return null !== $this->agentRuntime ? $this->agentRuntime->apply($turn->widget, $turn->owner, $options) : $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(WidgetTurn $turn, StoredVisitorMessage $stored, array $options): void
    {
        ignore_user_abort(true);

        $sse = new SseWriter();
        $run = $this->runs->begin(ChatRunService::ownerKeyForWidget($turn->session->getSessionId()), $stored->chat->getId(), (string) $stored->message->getId());
        $sse->attachRun($run);
        if (null !== $run) {
            $sse->send('run_started', ['runId' => $run->getRunId()]);
        }

        $chunks = new WidgetAnswerChunks();
        try {
            $result = $this->processor->processStream(
                $stored->message,
                static function (mixed $chunk) use ($chunks, $sse): void {
                    foreach ($chunks->push($chunk) as $piece) {
                        $sse->send('data', ['chunk' => $piece]);
                    }
                },
                static function (mixed $update) use ($sse): void {
                    if (is_array($update)) {
                        $sse->send((string) ($update['status'] ?? 'status'), self::statusPayload($update));
                    }
                },
                $options,
            );

            if (!($result['success'] ?? false)) {
                $this->answerFailed($stored, $result, $sse);

                return;
            }

            foreach ($chunks->finish() as $piece) {
                $sse->send('data', ['chunk' => $piece]);
            }
            $this->answers->store($turn, $stored, $chunks->text(), $result);
            $sse->send('complete', $this->completion($stored, $result, $chunks->finishReason()));
            $run?->finish(ChatRun::STATUS_COMPLETE, $stored->message->getId());
        } catch (\Throwable $e) {
            $this->streamFailed($turn, $stored, $sse, $e);
        } finally {
            // A run must never stay "running" after the worker is gone.
            $run?->finishFromRecordedOutcome();
        }
    }

    /**
     * The processor answered with a failure: the user-facing reason, in the
     * turn's language, and whether another model could answer.
     *
     * @param array<string, mixed> $result
     */
    private function answerFailed(StoredVisitorMessage $stored, array $result, SseWriter $sse): void
    {
        $language = is_array($result['classification'] ?? null) && isset($result['classification']['language'])
            ? (string) $result['classification']['language']
            : 'en';
        $view = $this->errors->presentFromResult($result, $language, false);
        $stored->message->setStatus('failed');
        $this->em->flush();

        $sse->send('error', [
            'error' => $view->userText,
            'errorReason' => $view->reason->value,
            'canRetryModel' => $view->canRetryWithOtherModel,
        ]);
    }

    /**
     * The stream broke: the message is failed and the session's counter goes
     * back down, so the dashboard and the export (which skip failed messages)
     * count the same.
     */
    private function streamFailed(WidgetTurn $turn, StoredVisitorMessage $stored, SseWriter $sse, \Throwable $e): void
    {
        $this->logger->error('Widget message streaming failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'widget_id' => $turn->widget->getWidgetId(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        try {
            $stored->message->setStatus('failed');
            $this->sessions->decrementMessageCount($turn->session, false);
            $this->em->flush();
        } catch (\Throwable $flushException) {
            // The entity manager may be closed after a database error.
            $this->logger->warning('Could not update message status after error', [
                'error' => $flushException->getMessage(),
            ]);
        }

        $sse->send('error', ['error' => 'Failed to process message']);
    }

    /**
     * A pipeline status, passed through with its message and metadata.
     *
     * @param array<mixed> $update
     *
     * @return array<string, mixed>
     */
    private static function statusPayload(array $update): array
    {
        $payload = [];
        if (isset($update['message'])) {
            $payload['message'] = $update['message'];
        }
        if (!empty($update['metadata'])) {
            $payload['metadata'] = $update['metadata'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function completion(StoredVisitorMessage $stored, array $result, ?string $finishReason): array
    {
        $metadata = $result['response']['metadata'] ?? [];
        $completion = [
            'messageId' => $stored->message->getId(),
            'chatId' => $stored->chat->getId(),
            'ragSources' => WidgetAnswerStore::ragSources($metadata),
            'metadata' => [
                'response' => $metadata,
                'classification' => $result['classification'] ?? null,
                'preprocessed' => $result['preprocessed'] ?? null,
                'search_results' => $result['search_results'] ?? null,
                'files' => $stored->fileIds,
            ],
        ];
        if ('length' === $finishReason) {
            $completion['truncated'] = true;
        }

        return $completion;
    }
}
