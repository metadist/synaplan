<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\AI\Exception\ProviderException;
use App\Entity\Message;
use App\Service\Message\ChatErrorNotifier;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ChatErrorView;
use App\Service\ModelConfigService;
use App\Service\Realtime\SseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * What the person sees when a turn fails.
 *
 * The error is one localized sentence with a named recovery; admins also
 * get the diagnostics. It is stored as an error reply — showing the model
 * the person picked, not "error" — so it survives a reload, and the
 * operators are notified. An explicit Stop is not a failure: the client
 * already stored the cancelled turn.
 */
final readonly class StreamFailureReporter
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChatErrorPresenter $chatErrorPresenter,
        private ChatErrorNotifier $chatErrorNotifier,
        private AuthorizationCheckerInterface $authorization,
        private ModelConfigService $modelConfigService,
        private StreamMessageMeta $meta,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether a processing result describes an explicit user cancellation.
     *
     * A cancelled turn is stored exactly once, by /save-cancelled; treated as
     * an error it got a second reply with the same tracking id, and the chat
     * showed two cancellation cards for one Stop click. The `cancelled`
     * marker is the intended signal. The message match is a safety net for a
     * path that still reports a cancel as a plain failure: one leaked result
     * would persist raw English text no translation can repair (#1501).
     *
     * @param array<string, mixed> $result
     */
    public function isCancellation(array $result): bool
    {
        if (true === ($result['success'] ?? false)) {
            return false;
        }

        if (true === ($result['cancelled'] ?? false)) {
            return true;
        }

        $error = $result['error'] ?? null;

        return \is_string($error) && 1 === preg_match('/cancell?ed by user/i', $error);
    }

    /**
     * The handler reported a failure: the sentence streams like an answer
     * and becomes the reply.
     *
     * @param array<string, mixed> $result
     */
    public function reportFailedResult(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, array $result): void
    {
        $intended = $state->intendedChat;
        $errorLang = self::errorLanguage($result, $turn->caller->uiLanguage);
        $errorView = $this->present($result, $errorLang);
        $this->chatErrorNotifier->notify($errorView, $intended['provider'] ?? ($result['provider'] ?? null), $turn->caller->user->getId(), [
            'model' => $intended['name'] ?? null,
            'chat_id' => $state->chat?->getId(),
        ]);

        $sse->send('data', ['chunk' => $errorView->userText]);

        // The original classification keeps the client's model choice right.
        $classification = $result['classification'] ?? null;
        $originalTopic = (is_array($classification) && isset($classification['topic'])) ? $classification['topic'] : null;
        $originalMediaType = self::originalMediaType($classification);

        $outgoing = $this->errorReply($turn, $state, $errorLang, $errorView->userText);
        if (!$turn->incognito) {
            $this->em->persist($outgoing);
            $this->em->flush();
        }
        $this->meta->stampAgentId($outgoing, $turn->options->agent?->agentId, $turn->options->agent?->versionId);

        $provider = $intended['provider'] ?? ($result['provider'] ?? 'system');
        $this->recordErrorModel($outgoing, (string) $provider, $intended['name'] ?? 'unknown', $intended['id'] ?? null, $errorView);
        if ($originalTopic) {
            $outgoing->setMeta('original_topic', (string) $originalTopic);
        }
        if (null !== $originalMediaType) {
            $outgoing->setMeta('original_media_type', $originalMediaType);
        }
        // Without it, error rows lose the sorting badge (#603).
        $this->meta->persistClassificationSortingMeta($outgoing, is_array($classification) ? $classification : null);

        $this->markIncomingFailed($turn, $state);

        $payload = [
            'messageId' => $outgoing->getId(),
            'trackId' => $turn->trackId,
            'provider' => $provider,
            'model' => $intended['name'] ?? 'unknown',
            'model_id' => $intended['id'],
            'topic' => 'ERROR',
            'originalTopic' => $originalTopic,
            'originalMediaType' => $originalMediaType,
            'language' => $errorLang,
            'aiModels' => $this->meta->buildAiModelsPayload($outgoing),
            ...$errorView->toSseFields(),
        ];
        if (isset($result['error_hint'])) {
            $payload['error_hint'] = $result['error_hint'];
        }

        $sse->send('complete', $payload);
    }

    /**
     * A provider or pipeline exception ended the turn.
     *
     * @return int|null the stored error reply, when there is one
     */
    public function reportException(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, \Exception $e): ?int
    {
        $user = $turn->caller->user;
        $providerName = $e instanceof ProviderException ? $e->getProviderName() : null;
        $failure = ['exception' => $e, 'error' => $e->getMessage()];
        if ($e instanceof ProviderException) {
            $this->logger->error('AI Provider failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'provider' => $providerName,
                'context' => $e->getContext(),
            ]);
            $failure += ['provider' => $providerName, 'context' => $e->getContext()];
        } else {
            $this->logger->error('Streaming failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $intended = $state->intendedChat;
        $fallbackProvider = $providerName ?? 'system';
        $errorView = $this->present($failure, $turn->caller->uiLanguage);
        $this->chatErrorNotifier->notify($errorView, $intended['provider'] ?? $fallbackProvider, $user->getId(), [
            'model' => $intended['name'] ?? null,
            'chat_id' => $state->chat?->getId(),
        ]);

        $messageId = $this->saveErrorReply($turn, $state, $errorView, $fallbackProvider);

        $errorData = [
            'error' => $errorView->userText,
            'provider' => $intended['provider'] ?? $fallbackProvider,
            'model' => $intended['name'] ?? 'unknown',
            'model_id' => $intended['id'],
            'topic' => 'ERROR',
            'trackId' => $turn->trackId,
            ...$errorView->toSseFields(),
        ];
        if ($messageId) {
            $errorData['messageId'] = $messageId;
        }

        $sse->send('error', $errorData);

        return $messageId;
    }

    /**
     * A non-streaming model reported a failure.
     *
     * @param array<string, mixed> $result
     */
    public function reportFailedBufferedResult(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, array $result, ?int $intendedModelId): void
    {
        $incoming = $state->incoming();
        $errorLang = self::errorLanguage($result, $incoming->getLanguage() ?: 'en');
        $errorView = $this->present($result, $errorLang);
        $this->chatErrorNotifier->notify($errorView, $result['provider'] ?? null, $turn->caller->user->getId(), [
            'model' => $intendedModelId ? $this->modelConfigService->getModelName($intendedModelId) : null,
            'chat_id' => $state->chat?->getId(),
        ]);

        $classification = $result['classification'] ?? null;
        $originalTopic = is_array($classification) && isset($classification['topic']) && is_string($classification['topic']) ? $classification['topic'] : null;
        $originalMediaType = self::originalMediaType($classification);

        $outgoing = $this->errorReply($turn, $state, $errorLang, $errorView->userText);
        if (!$turn->incognito) {
            $this->em->persist($outgoing);
            $this->em->flush();
        }

        $fallbackProvider = $result['provider'] ?? 'system';
        $displayProvider = $intendedModelId ? ($this->modelConfigService->getProviderForModel($intendedModelId) ?? $fallbackProvider) : $fallbackProvider;
        $displayModel = $intendedModelId ? ($this->modelConfigService->getModelName($intendedModelId) ?? 'unknown') : 'unknown';
        $this->recordErrorModel($outgoing, (string) $displayProvider, $displayModel, $intendedModelId, $errorView);
        if (null !== $originalTopic) {
            $outgoing->setMeta('original_topic', $originalTopic);
        }
        if (null !== $originalMediaType) {
            $outgoing->setMeta('original_media_type', $originalMediaType);
        }
        $this->meta->persistClassificationSortingMeta($outgoing, is_array($classification) ? $classification : null);

        $this->markIncomingFailed($turn, $state);

        $sse->send('data', ['chunk' => $errorView->userText]);
        $sse->send('complete', [
            'messageId' => $outgoing->getId(),
            'trackId' => $turn->trackId,
            'provider' => $displayProvider,
            'model' => $displayModel,
            'model_id' => $intendedModelId,
            'topic' => 'ERROR',
            'originalTopic' => $originalTopic,
            'originalMediaType' => $originalMediaType,
            'language' => $errorLang,
            'aiModels' => $this->meta->buildAiModelsPayload($outgoing),
            ...$errorView->toSseFields(),
        ]);
    }

    /**
     * A non-streaming model threw; nothing is stored.
     */
    public function reportBufferedException(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state, \Exception $e): void
    {
        $this->logger->error('Non-streaming processing failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        $errorLang = $state->incoming()->getLanguage() ?: 'en';
        $errorView = $this->present(['exception' => $e, 'error' => $e->getMessage()], $errorLang);
        $this->chatErrorNotifier->notify($errorView, 'system', $turn->caller->user->getId(), [
            'chat_id' => $state->chat?->getId(),
        ]);
        $sse->send('error', [
            'error' => $errorView->userText,
            'topic' => 'ERROR',
            'trackId' => $turn->trackId,
            'language' => $errorLang,
            ...$errorView->toSseFields(),
        ]);
    }

    /**
     * Stores the error reply of an exception. An incognito turn only flags
     * its transient incoming message; the SSE error carries the sentence.
     */
    private function saveErrorReply(StreamTurnRequest $turn, StreamTurnState $state, ChatErrorView $view, string $provider): ?int
    {
        $incoming = $state->incoming;
        if (!$incoming) {
            return null;
        }

        if ($turn->incognito) {
            $incoming->setTopic('ERROR');
            $incoming->setStatus('error');

            return null;
        }

        if (!$state->chat) {
            return null;
        }

        try {
            $outgoing = $this->errorReply($turn, $state, $turn->caller->uiLanguage, $view->userText);
            $this->em->persist($outgoing);
            $this->em->flush();
            $this->meta->stampAgentId($outgoing, $turn->options->agent?->agentId, $turn->options->agent?->versionId);

            $intended = $state->intendedChat;
            $this->recordErrorModel($outgoing, $intended['provider'] ?? $provider, $intended['name'] ?? 'unknown', $intended['id'] ?? null, $view);

            $incoming->setTopic('ERROR');
            $incoming->setStatus('error');
            $this->em->flush();

            return $outgoing->getId();
        } catch (\Exception $e) {
            $this->logger->error('Failed to save error message', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function errorReply(StreamTurnRequest $turn, StreamTurnState $state, string $language, string $text): Message
    {
        $outgoing = new Message();
        $outgoing->setUserId($turn->caller->user->getId());
        $outgoing->setChat($state->chat);
        $outgoing->setTrackingId((int) $turn->trackId);
        $outgoing->setProviderIndex($state->incoming()->getProviderIndex());
        $outgoing->setUnixTimestamp(time());
        $outgoing->setDateTime(date('YmdHis'));
        $outgoing->setMessageType('WEB');
        $outgoing->setFile(0);
        $outgoing->setTopic('ERROR');
        $outgoing->setLanguage($language);
        $outgoing->setText($text);
        $outgoing->setDirection('OUT');
        $outgoing->setStatus('complete');

        return $outgoing;
    }

    private function recordErrorModel(Message $outgoing, string $provider, string $model, ?int $modelId, ChatErrorView $view): void
    {
        $outgoing->setMeta('ai_chat_provider', $provider);
        $outgoing->setMeta('ai_chat_model', $model);
        if (null !== $modelId) {
            $outgoing->setMeta('ai_chat_model_id', (string) $modelId);
        }
        $this->meta->persistChatErrorMeta($outgoing, $view);
    }

    private function markIncomingFailed(StreamTurnRequest $turn, StreamTurnState $state): void
    {
        $incoming = $state->incoming();
        $incoming->setTopic('ERROR');
        $incoming->setStatus('error');

        if (!$turn->incognito) {
            $state->chat?->updateTimestamp();
            $this->em->flush();
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function present(array $result, string $language): ChatErrorView
    {
        return $this->chatErrorPresenter->presentFromResult($result, $language, $this->authorization->isGranted('ROLE_ADMIN'));
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function errorLanguage(array $result, string $fallback): string
    {
        $classification = $result['classification'] ?? null;
        if (is_array($classification) && isset($classification['language']) && is_string($classification['language']) && '' !== trim($classification['language'])) {
            return $classification['language'];
        }

        return '' !== trim($fallback) ? $fallback : 'en';
    }

    private static function originalMediaType(mixed $classification): ?string
    {
        if (!is_array($classification) || !isset($classification['media_type']) || !is_string($classification['media_type'])) {
            return null;
        }

        $mediaType = trim($classification['media_type']);

        return '' !== $mediaType ? $mediaType : null;
    }
}
