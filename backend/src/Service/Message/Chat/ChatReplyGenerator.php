<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\Service\Chat\ProviderTranscriptCapture;
use App\AI\Stream\VisibleAnswer;
use App\Service\Document\ChatDocumentOutput;
use App\Service\Message\ProgressCallback;
use Psr\Log\LoggerInterface;

/**
 * Calls the model for a chat turn and checks that a usable answer came back.
 *
 * A turn that edits an existing document runs the document tool loop
 * instead of a plain completion. Everything else is one chat call: read in
 * full for buffered channels (through a stream when the channel needs a
 * live signal while the model works), or forwarded token by token to the
 * web chat.
 */
final readonly class ChatReplyGenerator
{
    /**
     * Models copy the old "[YmdHis]: " history prefix into their answer.
     * One leading stamp is removed so it never reaches the person.
     */
    private const ECHOED_HISTORY_STAMP = '/^\[\d{14}\]:\s*/';

    public function __construct(
        private AiFacade $aiFacade,
        private ChatDocumentOutput $documents,
        private LoggerInterface $logger,
        private ProviderTranscriptCapture $transcripts,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $aiOptions
     * @param array<string, mixed>       $options   the turn options (`heartbeat`, `incognito`)
     */
    public function complete(ChatTurn $turn, ChatModelChoice $model, array $messages, array $aiOptions, array $options, ?callable $progressCallback): ChatReply
    {
        $userId = $turn->message->getUserId();
        $documentEdit = $this->documents->tryToolsEdit($turn->topic(), $model->modelId, $messages, $turn->message, $aiOptions, $progressCallback, !empty($options['incognito']));
        if (null !== $documentEdit) {
            return new ChatReply(
                content: $documentEdit->content,
                metadata: self::chosenModelMetadata($model, $documentEdit->usage),
                documentEdit: $documentEdit,
            );
        }

        $heartbeat = $options['heartbeat'] ?? null;
        if (is_callable($heartbeat)) {
            return $this->collectWithHeartbeat($messages, $userId, $aiOptions, $heartbeat, $model);
        }

        $response = $this->aiFacade->chat($messages, $userId, $aiOptions);

        return new ChatReply(
            content: $response['content'] ?? null,
            metadata: [
                'provider' => $response['provider'] ?? 'unknown',
                'model' => $response['model'] ?? 'unknown',
                'usage' => $response['usage'] ?? [],
                'response_id' => $response['response_id'] ?? null,
            ],
            toolCalls: $response['tool_calls'] ?? [],
            finishReason: is_string($response['finish_reason'] ?? null) ? $response['finish_reason'] : null,
        );
    }

    /**
     * Applies a document edit, refuses an answer the model cut off, and
     * removes an echoed history stamp.
     *
     * @return array{0: mixed, 1: array<string, mixed>} the answer and its metadata
     */
    public function settle(ChatReply $reply, ChatTurn $turn): array
    {
        $content = $reply->content;
        $metadata = $reply->metadata;
        if (null !== $reply->documentEdit) {
            $content = $this->documents->applyEditResult($reply->documentEdit, $turn->message, $metadata);
        }

        if (!is_string($content)) {
            return [$content, $metadata];
        }

        $this->rejectCutOffAnswer($content, $reply->finishReason, (string) ($metadata['provider'] ?? 'unknown'), (string) ($metadata['model'] ?? 'unknown'));

        $unstamped = preg_replace(self::ECHOED_HISTORY_STAMP, '', $content, 1);

        return [is_string($unstamped) ? $unstamped : $content, $metadata];
    }

    /**
     * Streams the answer to the web chat. The turn's PerfTimer records the
     * first visible token and the provider's total time.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $aiOptions
     */
    public function stream(ChatTurn $turn, ChatModelChoice $model, array $messages, array $aiOptions, callable $streamCallback, bool $incognito, ?callable $progressCallback): ChatReply
    {
        $message = $turn->message;
        $this->logger->info('ChatHandler: Calling AiFacade chatStream', [
            'provider' => $model->provider,
            'model' => $model->modelName,
            'user_id' => $message->getUserId(),
            'reasoning' => $aiOptions['reasoning'] ?? false,
        ]);

        // Announced here, not when the turn starts: the knowledge base,
        // memories and prompt assembly report their own steps, and claiming
        // "generating" before them would make the timeline go backwards.
        // It names the model actually called, so the timeline can say
        // "Sending your request to X".
        ProgressCallback::notify($progressCallback, 'generating', 'Generating response...', [
            'provider' => $model->provider,
            'model_name' => $model->modelName,
            'model_id' => $model->modelId,
            'stage' => 'request_sent',
        ]);

        $answer = new StreamedAnswer($streamCallback, $turn->perfTimer);
        $turn->perfTimer->start('provider_total');
        $this->transcripts->reset();
        $capture = empty($turn->classification['is_widget_mode'])
            && empty($turn->options['is_widget_mode'])
            && empty($turn->options['is_guest_mode']);
        if ($capture) {
            $this->transcripts->enable();
        }
        $documentEdit = $this->documents->tryToolsEdit($turn->topic(), $model->modelId, $messages, $message, $aiOptions, $progressCallback, $incognito);
        if (null !== $documentEdit) {
            $metadata = self::chosenModelMetadata($model, $documentEdit->usage);
            $this->documents->applyEditResult($documentEdit, $message, $metadata);
            $answer($documentEdit->content);
        } else {
            if ($capture) {
                $this->transcripts->record($messages);
            }
            $metadata = $this->aiFacade->chatStream($messages, $answer, $message->getUserId(), $aiOptions);
        }
        $turn->perfTimer->stop('provider_total');

        return new ChatReply(
            content: $answer->text,
            metadata: $metadata,
            toolCalls: $metadata['tool_calls'] ?? [],
            finishReason: self::finishReason($metadata, $answer->finishReason),
            documentEdit: $documentEdit,
            sawVisibleText: $answer->sawVisibleToken,
        );
    }

    /**
     * A streamed answer is stored only when it is readable: not cut off by
     * the output limit, and not empty.
     */
    public function assertStreamedAnswer(ChatReply $reply, ChatTurn $turn, ChatModelChoice $model): void
    {
        $provider = $reply->provider($model);
        $modelName = $reply->model($model);
        $this->rejectCutOffAnswer((string) $reply->content, $reply->finishReason, $provider, $modelName);

        if (!$reply->sawVisibleText) {
            $this->logger->warning('ChatHandler: Provider returned no visible streaming content', [
                'provider' => $provider,
                'model' => $modelName,
                'user_id' => $turn->message->getUserId(),
            ]);

            throw new ProviderException('The AI model returned an empty response. Please try again or select a different model.', $provider, ['model' => $modelName]);
        }

        $this->logger->info('ChatHandler: AiFacade chatStream returned', [
            'response_length' => strlen((string) $reply->content),
        ]);
    }

    /**
     * Channels without a stream (Telegram) still need a live signal while
     * the model works: the answer is streamed, every chunk beats the
     * heartbeat, and the same text chat() would return is collected.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $aiOptions
     */
    private function collectWithHeartbeat(array $messages, int $userId, array $aiOptions, callable $heartbeat, ChatModelChoice $model): ChatReply
    {
        $collected = '';
        $streamFinishReason = null;
        $streamMeta = $this->aiFacade->chatStream(
            $messages,
            function (string|array $chunk) use (&$collected, &$streamFinishReason, $heartbeat): void {
                if (is_array($chunk) && 'finish' === ($chunk['type'] ?? null) && is_string($chunk['finish_reason'] ?? null)) {
                    $streamFinishReason = $chunk['finish_reason'];
                }
                $collected .= StreamChunk::visibleText($chunk);
                $heartbeat();
            },
            $userId,
            $aiOptions,
        );

        return new ChatReply(
            content: $collected,
            metadata: [
                'provider' => $streamMeta['provider'] ?? ($model->provider ?? 'unknown'),
                'model' => $streamMeta['model'] ?? ($model->modelName ?? 'unknown'),
                'usage' => $streamMeta['usage'] ?? [],
                'response_id' => $streamMeta['response_id'] ?? null,
            ],
            toolCalls: $streamMeta['tool_calls'] ?? [],
            finishReason: self::finishReason($streamMeta, $streamFinishReason),
        );
    }

    /**
     * A thinking model that ran out of room must not be stored as a finished
     * reply. The step would show Done and the chat would say the answer was
     * never saved (#2264).
     */
    private function rejectCutOffAnswer(string $content, ?string $finishReason, string $provider, string $model): void
    {
        if (!VisibleAnswer::isUnusable($content, $finishReason)) {
            return;
        }

        if (VisibleAnswer::failedBecauseOutputWasCut($content, $finishReason)) {
            $this->logger->warning('ChatHandler: model output was cut off before a readable answer', [
                'provider' => $provider,
                'model' => $model,
                'finish_reason' => $finishReason,
            ]);

            throw new ProviderException('The model ran out of room before it finished the answer.', $provider, ['error_code' => 'max_tokens', 'model' => $model]);
        }

        $this->logger->warning('ChatHandler: Provider returned no visible streaming content', [
            'provider' => $provider,
            'model' => $model,
        ]);

        throw new ProviderException('The AI model returned an empty response. Please try again or select a different model.', $provider, ['model' => $model]);
    }

    /**
     * The provider's reported finish reason wins over the one the stream
     * carried in its last chunk.
     *
     * @param array<string, mixed> $metadata
     */
    private static function finishReason(array $metadata, ?string $streamed): ?string
    {
        return is_string($metadata['finish_reason'] ?? null) && '' !== $metadata['finish_reason']
            ? $metadata['finish_reason']
            : $streamed;
    }

    /**
     * Metadata for an answer that did not come from a provider response: the
     * document tool loop reports usage only.
     *
     * @param array<string, mixed> $usage
     *
     * @return array<string, mixed>
     */
    private static function chosenModelMetadata(ChatModelChoice $model, array $usage): array
    {
        return [
            'provider' => $model->provider ?? 'unknown',
            'model' => $model->modelName ?? 'unknown',
            'usage' => $usage,
            'response_id' => null,
        ];
    }
}
