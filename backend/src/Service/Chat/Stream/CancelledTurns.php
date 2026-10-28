<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Media\MediaCancellationStore;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stopping a turn, and storing what it said until it stopped.
 *
 * Stop flags the turn in the cancellation store, so a worker blocked in a
 * media poll (Higgsfield video) aborts the provider call too — closing the
 * EventSource cannot interrupt a worker that produces no output. One media
 * step can be stopped alone. Only the caller's own turn can be flagged.
 */
final readonly class CancelledTurns
{
    public function __construct(
        private EntityManagerInterface $em,
        private RateLimitService $rateLimitService,
        private MediaCancellationStore $cancellationStore,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Stores the partial answer of a cancelled turn as its reply.
     *
     * @return array<string, mixed> the success payload
     *
     * @throws StreamRequestRejected
     */
    public function saveReply(User $user, mixed $data): array
    {
        $trackId = $data['trackId'] ?? null;
        $chatId = $data['chatId'] ?? null;
        if (!$trackId || !$chatId) {
            throw StreamRequestRejected::json(['error' => 'Missing trackId or chatId'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $chat = $this->em->getRepository(Chat::class)->find((int) $chatId);
            if (!$chat || $chat->getUserId() !== $user->getId()) {
                throw StreamRequestRejected::json(['error' => 'Chat not found or access denied'], Response::HTTP_FORBIDDEN);
            }

            $incoming = $this->em->getRepository(Message::class)->findOneBy([
                'userId' => $user->getId(),
                'trackingId' => $trackId,
                'direction' => 'IN',
            ]);
            if (!$incoming) {
                $this->logger->warning('Incoming message not found for cancelled response', [
                    'user_id' => $user->getId(),
                    'track_id' => $trackId,
                ]);

                throw StreamRequestRejected::json(['error' => 'Incoming message not found'], Response::HTTP_NOT_FOUND);
            }

            return $this->storeReply($user, $chat, $incoming, $data);
        } catch (StreamRequestRejected $rejected) {
            throw $rejected;
        } catch (\Exception $e) {
            $this->logger->error('Failed to save cancelled message', [
                'user_id' => $user->getId(),
                'track_id' => $trackId,
                'error' => $e->getMessage(),
            ]);

            throw StreamRequestRejected::json(['success' => false, 'error' => 'Failed to save message'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @throws StreamRequestRejected
     */
    public function stop(User $user, mixed $data): void
    {
        $trackId = self::scalarField($data, 'trackId');

        $this->logger->info('Stop stream requested', [
            'user_id' => $user->getId(),
            'track_id' => $trackId,
        ]);

        if ('' !== $trackId) {
            $this->assertOwnsTrack($user, $trackId, self::optionalChatId($data));
            $this->cancellationStore->requestCancel($trackId);
        }
    }

    /**
     * Stops one running media step of a multi-task turn; its siblings keep
     * running.
     *
     * @throws StreamRequestRejected
     */
    public function cancelNode(User $user, mixed $data): void
    {
        $trackId = self::scalarField($data, 'trackId');
        $nodeId = self::scalarField($data, 'nodeId');
        if ('' === $trackId || '' === $nodeId) {
            throw StreamRequestRejected::json(['error' => 'trackId and nodeId are required'], Response::HTTP_BAD_REQUEST);
        }

        $this->assertOwnsTrack($user, $trackId, self::optionalChatId($data));
        $this->cancellationStore->requestCancel($trackId, $nodeId);

        $this->logger->info('Cancel media node requested', [
            'user_id' => $user->getId(),
            'track_id' => $trackId,
            'node_id' => $nodeId,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function storeReply(User $user, Chat $chat, Message $incoming, array $data): array
    {
        $content = $data['content'] ?? '';
        $provider = $data['provider'] ?? null;
        $model = $data['model'] ?? null;
        $topic = $data['topic'] ?? null;

        // The reply must sort after the prompt even when the person switched
        // chats within the same second.
        $responseTimestamp = max(time(), $incoming->getUnixTimestamp() + 1);

        $outgoing = new Message();
        $outgoing->setUserId($user->getId());
        $outgoing->setChat($chat);
        $outgoing->setTrackingId((int) $data['trackId']);
        $outgoing->setProviderIndex($incoming->getProviderIndex());
        $outgoing->setUnixTimestamp($responseTimestamp);
        $outgoing->setDateTime(date('YmdHis', $responseTimestamp));
        $outgoing->setMessageType($incoming->getMessageType());
        $outgoing->setFile(0);
        $outgoing->setTopic($incoming->getTopic());
        $outgoing->setLanguage($incoming->getLanguage());
        $outgoing->setText(is_scalar($content) ? (string) $content : '');
        $outgoing->setDirection('OUT');
        $outgoing->setStatus('cancelled');

        $this->em->persist($outgoing);
        $this->em->flush();

        $outgoing->setMeta('cancelled', 'true');
        $outgoing->setMeta('cancelled_at', date('Y-m-d H:i:s'));

        // The models the stream reported, else what the prompt recorded.
        $chatProvider = $provider ?? $incoming->getMeta('ai_chat_provider');
        $chatModel = $model ?? $incoming->getMeta('ai_chat_model');
        foreach ([
            'ai_chat_provider' => $chatProvider,
            'ai_chat_model' => $chatModel,
            'ai_sorting_provider' => $incoming->getMeta('ai_sorting_provider'),
            'ai_sorting_model' => $incoming->getMeta('ai_sorting_model'),
        ] as $key => $value) {
            if ($value) {
                $outgoing->setMeta($key, (string) $value);
            }
        }

        if ($topic) {
            $outgoing->setTopic((string) $topic);
        }
        $this->em->flush();

        $incoming->setStatus('cancelled');
        $this->em->flush();

        $this->recordUsage($user, $incoming, $data, $chatProvider, $chatModel);

        $this->logger->info('Cancelled message saved', [
            'user_id' => $user->getId(),
            'track_id' => $data['trackId'],
            'message_id' => $outgoing->getId(),
        ]);

        return [
            'success' => true,
            'messageId' => $outgoing->getId(),
            'topic' => $outgoing->getTopic(),
            'provider' => $chatProvider,
            'model' => $chatModel,
        ];
    }

    /**
     * #1146: whatever streamed before Stop still cost tokens, so a best-effort
     * entry is recorded from the partial answer. Without a model id the cost
     * resolves to 0, but the counters move. It is only accounting: a failure
     * here must not turn a stored cancellation into an error.
     *
     * @param array<string, mixed> $data
     */
    private function recordUsage(User $user, Message $incoming, array $data, mixed $chatProvider, mixed $chatModel): void
    {
        try {
            $cancelledModelId = $incoming->getMeta('ai_chat_model_id');
            $content = $data['content'] ?? '';
            \App\Service\Usage\LegacyUsageWriter::record($this->rateLimitService, $user, 'MESSAGES', [
                'provider' => $chatProvider ?? 'unknown',
                'model' => $chatModel ?? 'unknown',
                'model_id' => null !== $cancelledModelId && '' !== $cancelledModelId ? (int) $cancelledModelId : null,
                'chat_id' => (int) $data['chatId'],
                'source' => 'WEB',
                'response_text' => is_string($content) ? $content : '',
                'input_text' => $incoming->getText(),
                'status' => 'cancelled',
            ]);
        } catch (\Throwable $usageError) {
            $this->logger->warning('Failed to record usage for cancelled chat turn', [
                'user_id' => $user->getId(),
                'track_id' => $data['trackId'],
                'error' => $usageError->getMessage(),
            ]);
        }
    }

    /**
     * A persisted turn is proven by a message with this tracking id and the
     * caller's user id (the incoming message is stored before generation).
     * An incognito turn is never stored: only the owner of the chat it names
     * may cancel it, and never a track that belongs to someone else.
     */
    private function assertOwnsTrack(User $user, string $trackId, ?int $chatId): void
    {
        if ('' === $trackId || !ctype_digit($trackId)) {
            throw StreamRequestRejected::json(['error' => 'trackId is required'], Response::HTTP_BAD_REQUEST);
        }

        $messages = $this->em->getRepository(Message::class);
        if (null !== $messages->findOneBy(['trackingId' => (int) $trackId, 'userId' => $user->getId()])) {
            return;
        }

        $foreign = $messages->findOneBy(['trackingId' => (int) $trackId]);
        if (null === $foreign && null !== $chatId) {
            $chat = $this->em->getRepository(Chat::class)->find($chatId);
            if (null !== $chat && $chat->getUserId() === $user->getId()) {
                return;
            }
        }

        throw StreamRequestRejected::json(['error' => 'Chat not found or access denied'], Response::HTTP_FORBIDDEN);
    }

    private static function scalarField(mixed $data, string $key): string
    {
        return is_array($data) && isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
    }

    private static function optionalChatId(mixed $data): ?int
    {
        if (!is_array($data) || !isset($data['chatId']) || !is_numeric($data['chatId'])) {
            return null;
        }

        $chatId = (int) $data['chatId'];

        return $chatId > 0 ? $chatId : null;
    }
}
