<?php

namespace App\Service\WhatsApp\Turn;

use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\SelfAware\Docs\PlatformDocReferenceResolver;
use App\Service\Usage\RecordedUsage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores a WhatsApp reply that reached the phone: the outgoing message in the
 * sender's chat with the channel's ids, the model with its sorting and usage,
 * the web sources and the docs it cited; then refreshes the chat's summary.
 */
final readonly class WhatsAppAnswerStore
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConversationSummaryRefreshDispatcher $summaryRefreshDispatcher,
    ) {
    }

    /**
     * Persist an outgoing WhatsApp message.
     *
     * Mirrors the web-search metadata stored on the incoming message
     * (issue #652). The chat history endpoint
     * ({@see \App\Controller\ChatController::getMessages()}) renders the
     * "Quellen" panel based on `web_search_query` + `web_search_results_count`
     * on the OUTGOING message, then resolves the actual citations from the
     * INCOMING message via `SearchResultRepository::findByMessage()`. Skipping
     * this mirror was the root cause of issue #652 — the platform view simply
     * didn't know that the WhatsApp answer was backed by citations.
     *
     * Also records the serve URL + type when the response carried
     * AI-generated media so the web chat history can replay the
     * image/video/audio player (issue #626). WhatsApp itself already received
     * the asset via sendMedia(), but the cross-channel mirror lives in the DB
     * row.
     *
     * @param array{query?: string, results?: array}|null $searchResults  web-search payload
     *                                                                    carried alongside the
     *                                                                    AI response
     * @param array{path: string, type: string}|null      $generatedFile  optional file metadata
     *                                                                    produced by
     *                                                                    MediaGenerationHandler
     *                                                                    (already normalized to a
     *                                                                    serve URL)
     * @param array<string, mixed>|null                   $aiMetadata     chat model metadata from
     *                                                                    processStream (provider,
     *                                                                    model, model_id, usage)
     * @param array<string, mixed>|null                   $classification sorter metadata for
     *                                                                    ai_sorting_* MessageMeta
     */
    public function store(
        User $user,
        IncomingMessageDto $dto,
        string $text,
        string $externalId,
        ?Chat $chat = null,
        ?array $searchResults = null,
        ?array $generatedFile = null,
        ?array $aiMetadata = null,
        ?array $classification = null,
    ): void {
        $outgoingMessage = new Message();
        $outgoingMessage->setUserId($user->getId());
        if ($chat) {
            $outgoingMessage->setChat($chat);
        }
        $outgoingMessage->setTrackingId(time());
        $outgoingMessage->setProviderIndex('WHATSAPP');
        $outgoingMessage->setUnixTimestamp(time());
        $outgoingMessage->setDateTime(date('YmdHis'));
        $outgoingMessage->setMessageType('WTSP');
        $outgoingMessage->setFile(null !== $generatedFile ? 1 : 0);
        $outgoingMessage->setFilePath($generatedFile['path'] ?? '');
        $outgoingMessage->setFileType($generatedFile['type'] ?? '');
        $outgoingMessage->setTopic($classification['topic'] ?? 'CHAT');
        $outgoingMessage->setLanguage($classification['language'] ?? 'en');
        $outgoingMessage->setText($text);
        $outgoingMessage->setDirection('OUT');
        $outgoingMessage->setStatus('sent');

        $this->em->persist($outgoingMessage);
        $this->em->flush();

        $outgoingMessage->setMeta('channel', 'whatsapp');
        $outgoingMessage->setMeta('to_phone', $dto->from);
        $outgoingMessage->setMeta('from_phone_number_id', $dto->phoneNumberId);
        if ($dto->displayPhoneNumber) {
            $outgoingMessage->setMeta('from_display_phone', $dto->displayPhoneNumber);
        }
        $outgoingMessage->setMeta('external_id', $externalId);

        $this->attachModelMetadata($outgoingMessage, $aiMetadata, $classification, $searchResults);

        $this->em->flush();

        // Rolling-summary refresh, exactly like the web channel after its OUT
        // persist (channel parity): async worker fold, never blocks the reply.
        if ($chat && null !== $chat->getId()) {
            $this->summaryRefreshDispatcher->dispatch((int) $chat->getId(), (int) $user->getId());
        }
    }

    /**
     * @param array<string, mixed>|null $aiMetadata
     * @param array<string, mixed>|null $classification
     * @param array<string, mixed>|null $searchResults
     */
    private function attachModelMetadata(Message $outgoingMessage, ?array $aiMetadata, ?array $classification, ?array $searchResults): void
    {
        // #975: mirror web StreamController model metadata so the platform
        // chat lightbulb shows which chat/sorting models produced the reply.
        $outgoingMessage->setMeta('ai_chat_provider', (string) ($aiMetadata['provider'] ?? 'unknown'));
        $outgoingMessage->setMeta('ai_chat_model', (string) ($aiMetadata['model'] ?? 'unknown'));
        if (!empty($aiMetadata['model_id'])) {
            $outgoingMessage->setMeta('ai_chat_model_id', (string) $aiMetadata['model_id']);
        }
        if (!empty($aiMetadata['usage']) && is_array($aiMetadata['usage'])) {
            $outgoingMessage->setMeta('ai_chat_usage', (string) json_encode($aiMetadata['usage']));
        }
        $recordedChatUsage = $aiMetadata['recorded_chat_usage'] ?? null;
        if ($recordedChatUsage instanceof RecordedUsage) {
            $recordedChatUsage->attachChatCost($outgoingMessage);
        }
        if (!empty($classification['sorting_provider'])) {
            $outgoingMessage->setMeta('ai_sorting_provider', (string) $classification['sorting_provider']);
        }
        if (!empty($classification['sorting_model_name'])) {
            $outgoingMessage->setMeta('ai_sorting_model', (string) $classification['sorting_model_name']);
        }
        if (!empty($classification['sorting_model_id'])) {
            $outgoingMessage->setMeta('ai_sorting_model_id', (string) $classification['sorting_model_id']);
        }

        $usageExtra = [];
        if (is_array($classification['sorting_usage'] ?? null)) {
            $usageExtra[] = RecordedUsage::fromSortingUsage(
                $classification['sorting_usage'],
                isset($classification['sorting_provider']) ? (string) $classification['sorting_provider'] : null,
                isset($classification['sorting_model_name']) ? (string) $classification['sorting_model_name'] : null,
            );
        }
        foreach (['planning_usage', 'transcription_usage'] as $usageKey) {
            if (is_array($aiMetadata[$usageKey] ?? null)) {
                $usageExtra[] = $aiMetadata[$usageKey];
            }
        }
        if ([] !== $usageExtra) {
            $outgoingMessage->setMeta('ai_usage_extra', (string) json_encode($usageExtra));
        }

        $resultsList = $searchResults['results'] ?? null;
        if (is_array($resultsList) && [] !== $resultsList) {
            $outgoingMessage->setMeta('web_search_query', (string) ($searchResults['query'] ?? ''));
            $outgoingMessage->setMeta('web_search_results_count', (string) count($resultsList));
        }
        if (is_array($aiMetadata)) {
            $docs = PlatformDocReferenceResolver::encodeDocsMeta($aiMetadata);
            if (null !== $docs) {
                $outgoingMessage->setMeta('docs', $docs);
            }
        }
    }
}
