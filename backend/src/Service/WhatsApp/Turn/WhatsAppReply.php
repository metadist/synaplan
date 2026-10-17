<?php

namespace App\Service\WhatsApp\Turn;

use App\AI\Stream\StreamChunk;
use App\Entity\Message;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ExternalReplyReferences;
use App\Service\Message\GeneratedMediaTextRenderer;
use App\Service\Message\MessageProcessor;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use App\Service\WhatsApp\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Last stage of a WhatsApp turn: runs the message through the AI pipeline
 * behind a typing indicator, composes the answer once (doc tags as links,
 * media tags rendered, web sources for a text reply, usage recorded) and
 * hands it to the delivery. A failed run is told to the sender in their
 * language.
 */
final readonly class WhatsAppReply
{
    /**
     * Maximum number of web sources appended to a WhatsApp reply.
     *
     * Keeping the list short prevents the 4096-char message limit from
     * pushing the actual answer into the second chunk, and it matches the
     * "top N" UX that the platform chat view already shows next to the
     * response.
     */
    private const MAX_WHATSAPP_SOURCES = 5;

    public function __construct(
        private WhatsAppService $whatsApp,
        private MessageProcessor $messageProcessor,
        private ChatErrorPresenter $chatErrorPresenter,
        private ExternalReplyReferences $externalReplyReferences,
        private RateLimitService $rateLimitService,
        private WhatsAppReplyDelivery $delivery,
        private DiscordNotificationService $discord,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ?GeneratedMediaTextRenderer $mediaTextRenderer = null,
    ) {
    }

    /**
     * @return array<string, mixed> the webhook's answer for this message
     */
    public function answer(InboundWhatsApp $turn, StoredWhatsAppMessage $stored): array
    {
        $dto = $turn->dto;
        $message = $stored->message;
        $this->whatsApp->markAsRead($dto->messageId, $dto->phoneNumberId, withTypingIndicator: true);

        $collectedResponse = '';
        $result = $this->messageProcessor->processStream(
            $message,
            function (string|array $chunk, array $metadata = []) use (&$collectedResponse): void {
                // Only visible answer text is collected, reasoning and finish chunks are dropped (#1067).
                $collectedResponse .= StreamChunk::visibleText($chunk);
            },
            null,
            $this->processingOptions($turn),
        );
        if (!$result['success']) {
            return $this->failed($turn, $message, $result);
        }

        $answer = $this->compose($turn, $message, $result, $collectedResponse);
        $responseSent = $this->delivery->deliver($turn, $stored, $answer);
        if (!$responseSent) {
            $this->logger->error('WhatsApp: No response sent to user', [
                'message_id' => $dto->messageId,
                'from' => $dto->from,
                'response_text_length' => strlen($answer->text),
            ]);
            $this->discord->notifyWhatsAppError(
                'processing',
                $dto->from,
                $message->getText(),
                'No response could be sent to user',
                ['message_type' => $dto->type],
                $turn->user->getId(),
            );
        }

        $fileData = $answer->file();

        return [
            'success' => true,
            'message_id' => $dto->messageId,
            'response_sent' => $responseSent,
            'response_type' => $responseSent ? ($turn->answersBySpeech && !$fileData ? 'tts' : ($fileData ? 'audio' : 'text')) : 'none',
        ];
    }

    /**
     * Append a compact list of web-search citations to a WhatsApp reply.
     *
     * Issue #652: WhatsApp users previously had to ask "wo sind die quellen?"
     * to surface the references the AI relied on. The platform UI shows the
     * sources via metadata; for the mobile channel we inline them so the
     * citations travel with the answer.
     *
     * Each entry is rendered as "*[N] Title*\n<url>" — WhatsApp turns bare
     * URLs into clickable previews and respects `*…*` as bold. Empty URLs
     * are skipped so we never produce naked numbers in the citation block.
     *
     * @param string                           $responseText The AI's answer text (already converted to WhatsApp markdown by sendMessage())
     * @param array<int, array<string, mixed>> $sources      Raw Brave-style search hits as returned by BraveSearchService
     */
    public static function appendWhatsAppSources(string $responseText, array $sources): string
    {
        $entries = [];
        $position = 1;

        foreach ($sources as $source) {
            if (count($entries) >= self::MAX_WHATSAPP_SOURCES) {
                break;
            }

            $url = trim((string) ($source['url'] ?? ''));
            if ('' === $url) {
                continue;
            }

            $title = trim((string) ($source['title'] ?? ''));
            if ('' === $title) {
                $title = $url;
            }

            // Newlines inside the title would break WhatsApp's list rendering
            // and confuse the markdown bold delimiters below.
            $title = preg_replace('/\s+/', ' ', $title) ?? $title;

            $entries[] = sprintf('*[%d] %s*'."\n".'%s', $position, $title, $url);
            ++$position;
        }

        if ([] === $entries) {
            return $responseText;
        }

        $separator = '' === trim($responseText) ? '' : "\n\n";

        return $responseText.$separator.'🔗 *Quellen:*'."\n".implode("\n\n", $entries);
    }

    /**
     * An image without a caption is described; with one, the caption is the
     * question and the classifier routes it.
     *
     * @return array<string, mixed>
     */
    private function processingOptions(InboundWhatsApp $turn): array
    {
        $options = [];
        if (null !== $turn->agentId) {
            $options['agentId'] = $turn->agentId;
        }
        if ('image' === $turn->dto->type && empty($turn->dto->incomingMsg['image']['caption'] ?? null)) {
            $options['force_image_description'] = true;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function failed(InboundWhatsApp $turn, Message $message, array $result): array
    {
        $dto = $turn->dto;
        $errorView = $this->chatErrorPresenter->presentFromResult($result, $message->getLanguage() ?: 'de', false);
        $this->whatsApp->sendMessage($dto->from, $errorView->userText, $dto->phoneNumberId);
        // Operators get the raw text.
        $this->discord->notifyWhatsAppError(
            'processing',
            $dto->from,
            $message->getText(),
            $errorView->rawMessage,
            ['message_type' => $dto->type],
            $turn->user->getId(),
        );

        return ['success' => false, 'message_id' => $dto->messageId, 'error' => $errorView->userText];
    }

    /**
     * The web sources are kept on the inbound message too, so the chat view
     * shows them next to the answer (#652).
     *
     * @param array<string, mixed> $result
     */
    private function compose(InboundWhatsApp $turn, Message $message, array $result, string $collectedResponse): WhatsAppAnswer
    {
        $user = $turn->user;
        // The stored reply keeps [Doc:slug] so the web chat can render the pill;
        // the phone gets the same text with those tags turned into links.
        $responseText = $this->externalReplyReferences->resolveStored((string) ($result['response']['content'] ?? $collectedResponse), $user);
        $userFacing = null !== $this->mediaTextRenderer
            ? $this->mediaTextRenderer->forUser($responseText, $message->getLanguage(), $user->getLocale())
            : GeneratedMediaTextRenderer::renderModel($responseText);
        $channelText = $this->externalReplyReferences->resolveDocTags($userFacing);
        $metadata = $result['response']['metadata'] ?? [];
        $classification = is_array($result['classification'] ?? null) ? $result['classification'] : null;

        $searchResults = $result['search_results'] ?? null;
        $searchResultsItems = $searchResults['results'] ?? [];
        $hasSearchResults = is_array($searchResultsItems) && [] !== $searchResultsItems;
        if ($hasSearchResults) {
            $message->setMeta('web_search_query', (string) ($searchResults['query'] ?? ''));
            $message->setMeta('web_search_results_count', (string) count($searchResultsItems));
            $this->em->flush();
        }

        $recordedChatUsage = $this->rateLimitService->recordUsage($user, 'MESSAGES', [
            'provider' => $metadata['provider'] ?? 'unknown',
            'model' => $metadata['model'] ?? 'unknown',
            'usage' => $metadata['usage'] ?? [],
            'model_id' => $metadata['model_id'] ?? null,
            'source' => 'WHATSAPP',
            'response_text' => $responseText,
            'input_text' => $message->getText(),
        ]);
        $metadata['recorded_chat_usage'] = $recordedChatUsage;
        $rawTranscriptionUsage = $message->getMeta('ai_transcription_usage');
        if (null !== $rawTranscriptionUsage && '' !== $rawTranscriptionUsage) {
            $transcriptionUsage = json_decode($rawTranscriptionUsage, true);
            if (is_array($transcriptionUsage)) {
                $metadata['transcription_usage'] = $transcriptionUsage;
            }
        }

        return new WhatsAppAnswer(
            $responseText,
            $channelText,
            $hasSearchResults ? self::appendWhatsAppSources($channelText, $searchResultsItems) : $channelText,
            $metadata,
            $classification,
            $searchResults,
        );
    }
}
