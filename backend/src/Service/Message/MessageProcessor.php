<?php

namespace App\Service\Message;

use App\AI\Exception\ProviderException;
use App\Entity\Message;
use App\Service\Exception\StreamCancelledException;
use App\Service\Exception\VisionModelRequiredException;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\Processing\TurnClassificationResolver;
use App\Service\Message\Processing\TurnDispatcher;
use App\Service\Message\Processing\TurnHistoryLoader;
use App\Service\Message\Processing\TurnStatus;
use App\Service\Message\Processing\TurnWebSearch;
use Psr\Log\LoggerInterface;

/**
 * Runs one turn: preprocessing, classification, history, linked pages and
 * web search, rolling summary, then the handler that answers.
 *
 * process() is the buffered turn (email, MCP, Saved Tasks, webhooks, models
 * that cannot stream); processStream() the streamed one. Both run the same
 * stages; where they still differ, the stage names it (code cleanup V3,
 * master plan §3, nine differences).
 */
final readonly class MessageProcessor
{
    public function __construct(
        private MessagePreProcessor $preProcessor,
        private TurnClassificationResolver $classifications,
        private TurnHistoryLoader $history,
        private TurnWebSearch $webSearch,
        private TurnDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable             $streamCallback receives the answer chunks
     * @param callable|null        $statusCallback receives status updates
     * @param array<string, mixed> $options        processing options (reasoning, temperature, …)
     *
     * @return array<string, mixed> processing result with metadata; the content was streamed
     */
    public function processStream(Message $message, callable $streamCallback, ?callable $statusCallback = null, array $options = []): array
    {
        $status = new TurnStatus($statusCallback, $this->logger);
        $status->report('started', 'Message processing started');

        $perfTimer = $options['perf_timer'] ?? null;
        if (!$perfTimer instanceof PerfTimer) {
            $perfTimer = new PerfTimer();
            $options['perf_timer'] = $perfTimer;
        }

        try {
            $perfTimer->start('preprocess');
            [$message, $preprocessed] = $this->preprocess($message, $status);
            $perfTimer->stop('preprocess');

            $isAgainRequest = !empty($options['is_again']);
            $options = $this->classifications->pinAgent($message, $options);
            $hasFixedPrompt = TurnClassificationResolver::hasFixedPrompt($options);
            $sorting = [null, null, null];

            if ($hasFixedPrompt) {
                $classification = $this->classifications->fixedPromptForStreamedTurn($message, $options, $status);
            } elseif ($isAgainRequest) {
                $classification = $this->classifications->againForStreamedTurn($message, $options, $status);
            } else {
                $sorting = $this->classifications->announceSorting($message, $status);
            }

            $perfTimer->start('history');
            $conversationHistory = $this->history->load($message, $options);
            $perfTimer->stop('history');

            if (!$isAgainRequest && !$hasFixedPrompt) {
                if (!empty($options['force_image_description'])) {
                    $classification = $this->classifications->forcedImage($message, $options);
                } else {
                    $perfTimer->start('classify');
                    $classification = $this->classifications->classify($message, $conversationHistory, $options);
                    $perfTimer->stop('classify');
                }
                $classification = $this->classifications->afterClassify($classification, $options);
                [$classification, $sorting] = $this->classifications->finish($classification, $options, $status, $sorting);
                $this->dispatcher->shadowPlan($message, $conversationHistory, $perfTimer, $classification);
            }

            $classification = TurnClassificationResolver::withWidgetMode($classification ?? [], $options);
            $classification = TurnClassificationResolver::withRagScope($classification, $options);
            $classification = TurnDispatcher::tagSavedTaskRun($classification, $options);
            [$options, $promptMetadata] = $this->classifications->promptForStreamedTurn($message, $classification, $options, $perfTimer);

            ['classification' => $classification, 'searchResults' => $searchResults]
                = $this->webSearch->run($message, $classification, $promptMetadata, $options, $conversationHistory, $status, $perfTimer);

            [$options, $conversationHistory] = $this->history->applyRollingSummary($message, $classification, $options, $conversationHistory, $perfTimer);

            $this->dispatcher->announce($message, $status);
            if ($searchResults) {
                $options['search_results'] = $searchResults;
            }

            $perfTimer->start('handler_total');
            $response = $this->dispatcher->dispatchStreamed($message, $conversationHistory, $classification, $streamCallback, $statusCallback, $options);
            $perfTimer->stop('handler_total');

            return [
                'success' => true,
                'classification' => self::withSorting($this->dispatcher->applyEffectiveClassification($classification, $response), $sorting),
                'response' => $response,
                'preprocessed' => $preprocessed,
                'search_results' => $searchResults,
            ];
        } catch (StreamCancelledException $e) {
            // An explicit Stop is not a failure: the cancelled turn is already
            // persisted by /save-cancelled, and an error on top of it would
            // show two cancellation cards for one click.
            $this->logger->info('Streamed turn cancelled by user', ['message_id' => $message->getId()]);

            return ['success' => false, 'cancelled' => true, 'error' => $e->getMessage(), 'classification' => $classification ?? null];
        } catch (VisionModelRequiredException $e) {
            $this->logger->warning('Vision-capable model required for image attachments', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage(), 'error_hint' => VisionModelRequiredException::HINT_CODE, 'classification' => $classification ?? null];
        } catch (ProviderException $e) {
            return $this->providerFailure($e, $classification ?? null);
        } catch (\Throwable $e) {
            // \Throwable, so a TypeError in a provider degrades to an error
            // result the caller can render instead of an HTML 500.
            $this->logger->error('Message processing failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return ['success' => false, 'error' => $e->getMessage(), 'classification' => $classification ?? null, 'exception' => $e];
        }
    }

    /**
     * @param array<string, mixed> $options        processing options (fixed_task_prompt, model_id, …); an array
     *                                             callable here is the status callback (old signature)
     * @param callable|null        $statusCallback receives status updates
     *
     * @return array<string, mixed> processing result
     */
    public function process(Message $message, array $options = [], ?callable $statusCallback = null): array
    {
        if (is_callable($options) && null === $statusCallback) {
            $statusCallback = $options;
            $options = [];
        }
        $status = new TurnStatus($statusCallback, $this->logger);
        $status->report('started', 'Message processing started');

        try {
            [$message, $preprocessed] = $this->preprocess($message, $status);

            $isAgainRequest = !empty($options['is_again']);
            $options = $this->classifications->pinAgent($message, $options);
            $hasFixedPrompt = TurnClassificationResolver::hasFixedPrompt($options);
            $sorting = [null, null, null];
            if (!$hasFixedPrompt && !$isAgainRequest) {
                $sorting = $this->classifications->announceSorting($message, $status);
            }

            $conversationHistory = $this->history->load($message, $options);

            if ($hasFixedPrompt) {
                $classification = $this->classifications->fixedPromptForBufferedTurn($message, $options, $status);
            } elseif (!empty($options['force_image_description'])) {
                $classification = $this->classifications->forcedImageForBufferedTurn($message, $options, $status);
            } elseif ($isAgainRequest) {
                $classification = $this->classifications->againForBufferedTurn($message, $options, $status);
            } else {
                $classification = $this->classifications->afterClassify($this->classifications->classify($message, $conversationHistory, $options), $options);
                [$classification, $sorting] = $this->classifications->finish($classification, $options, $status, $sorting);
                $this->dispatcher->shadowPlan($message, $conversationHistory, null, $classification);
            }

            $classification = TurnClassificationResolver::withWidgetMode($classification, $options);
            $classification = TurnClassificationResolver::withRagScope($classification, $options);
            $classification = TurnDispatcher::tagSavedTaskRun($classification, $options);
            [$classification, $options, $promptMetadata] = $this->classifications->promptForBufferedTurn($message, $classification, $options);

            ['classification' => $classification, 'searchResults' => $searchResults]
                = $this->webSearch->run($message, $classification, $promptMetadata, $options, $conversationHistory, $status);
            if ($searchResults) {
                $classification['search_results'] = $searchResults;
            }

            // The same read-only summary as the stream, so email, MCP and
            // webhook turns get the same long-thread context.
            [$options, $conversationHistory] = $this->history->applyRollingSummary($message, $classification, $options, $conversationHistory);

            $this->dispatcher->announce($message, $status);
            $response = $this->dispatcher->dispatchBuffered($message, $conversationHistory, $classification, $statusCallback, $options);
            $status->report('complete', 'Response generated', [
                'provider' => $response['metadata']['provider'] ?? 'unknown',
                'model' => $response['metadata']['model'] ?? 'unknown',
            ]);

            $classification = self::withSorting($this->dispatcher->applyEffectiveClassification($classification, $response), $sorting);
            unset($classification['search_results']);

            return [
                'success' => true,
                'response' => $response,
                'classification' => $classification,
                'preprocessing' => $preprocessed,
                'search_results' => $searchResults,
            ];
        } catch (VisionModelRequiredException $e) {
            $this->logger->warning('Vision-capable model required for image attachments', ['message_id' => $message->getId(), 'error' => $e->getMessage()]);
            $status->report('error', 'Processing failed');

            return ['success' => false, 'error' => $e->getMessage(), 'error_hint' => VisionModelRequiredException::HINT_CODE, 'classification' => $classification ?? null, 'exception' => $e];
        } catch (ProviderException $e) {
            $status->report('error', 'Processing failed');

            return $this->providerFailure($e, $classification ?? null);
        } catch (\Throwable $e) {
            $errorDetails = [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];
            $this->logger->error('Message processing failed', $errorDetails);
            $status->report('error', 'Processing failed');

            return ['success' => false, 'error' => $e->getMessage(), 'details' => $errorDetails, 'classification' => $classification ?? null, 'exception' => $e];
        }
    }

    /**
     * Files are downloaded and parsed into the Message entity in place.
     *
     * @return array{0: Message, 1: array{hasFiles: bool}}
     */
    private function preprocess(Message $message, TurnStatus $status): array
    {
        $status->report('preprocessing', 'Downloading and parsing files...');
        $message = $this->preProcessor->process($message);
        if ($message->getFile() > 0 && $message->getFileText()) {
            $status->report('preprocessing', 'File processed and text extracted');
        }

        return [$message, ['hasFiles' => $message->getFile() > 0]];
    }

    /**
     * The install command and suggested models in the exception context reach
     * the caller, so the error card can name a recovery.
     *
     * @param array<string, mixed>|null $classification
     *
     * @return array<string, mixed>
     */
    private function providerFailure(ProviderException $e, ?array $classification): array
    {
        $this->logger->error('AI Provider failed', [
            'error' => $e->getMessage(),
            'provider' => $e->getProviderName(),
            'context' => $e->getContext(),
        ]);

        $result = [
            'success' => false,
            'error' => $e->getMessage(),
            'provider' => $e->getProviderName(),
            'classification' => $classification,
            'exception' => $e,
        ];
        if ($context = $e->getContext()) {
            $result['context'] = $context;
        }

        return $result;
    }

    /**
     * The sorting model goes back on the result (StreamController stores it),
     * never to the handler.
     *
     * @param array<string, mixed>                $classification
     * @param array{0: mixed, 1: mixed, 2: mixed} $sorting
     *
     * @return array<string, mixed>
     */
    private static function withSorting(array $classification, array $sorting): array
    {
        $classification['sorting_model_id'] = $sorting[0];
        $classification['sorting_provider'] = $sorting[1];
        $classification['sorting_model_name'] = $sorting[2];

        return $classification;
    }
}
