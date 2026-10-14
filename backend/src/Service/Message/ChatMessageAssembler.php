<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Service\Context\ContextCondenser;
use App\Service\Context\ModelContextWindow;
use App\Service\Exception\VisionImageUnprocessableException;
use App\Service\Prompt\ChatPromptBlocks;
use App\Service\Vision\VisionAttachmentEncoder;
use Psr\Log\LoggerInterface;

/**
 * The message array a chat turn hands to the model.
 *
 * Two shapes: the buffered channels (email, webhook, MCP) send the
 * current turn as the legacy BTEXT JSON envelope, the streamed web chat
 * as plain text. Both replay the thread under the same history rules,
 * fit attachments into the context window, and attach images for
 * vision models.
 */
final readonly class ChatMessageAssembler
{
    /** Flat attachment cut used only when no context budget is available. */
    private const LEGACY_ATTACHMENT_CHARS = 10000;

    public function __construct(
        private VisionAttachmentEncoder $vision,
        private ConversationHistoryRenderer $history,
        private LoggerInterface $logger,
        private ?ContextCondenser $contextCondenser = null,
        private ?ModelContextWindow $modelContextWindow = null,
    ) {
    }

    public function buffered(?string $systemPrompt, array $thread, Message $currentMessage, array $options = []): array
    {
        // A spoken question is a chat turn, not a channel record: the model
        // must read the words, not a BTEXT/BDATETIME envelope it then describes.
        if (true === ($options['plain_user_turn'] ?? false)) {
            return $this->streaming($systemPrompt, $thread, $currentMessage, $options);
        }

        $messages = [];
        if (null !== $systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        // Check if we should include images (only if vision model is available)
        $includeImages = $options['include_images'] ?? false;
        $generatedImages = $this->vision->generatedImagesForVision($currentMessage, $thread, $options);
        $mediaReferences = $this->history->mediaReferences($currentMessage, $thread);

        // Earlier turns as plain role/content messages.
        foreach ($thread as $msg) {
            $role = 'IN' === $msg->getDirection() ? 'user' : 'assistant';
            $content = $this->history->humanizeFileMarkers($msg->getText());

            // See streaming(): keep generated media visible so the
            // model does not deny it exists on a later turn (#1596).
            if ('assistant' === $role && isset($mediaReferences[$msg->getId()])) {
                $content = '' === trim($content)
                    ? $mediaReferences[$msg->getId()]
                    : $content."\n\n".$mediaReferences[$msg->getId()];
            }

            // For user messages in thread, include images for vision (if enabled)
            if ('user' === $role && $includeImages) {
                $imageUrls = $this->vision->imageDataUrls($msg);
                $messageContent = $this->buildMultimodalContent($content, $imageUrls);
            } else {
                $messageContent = $content;
            }

            // #1115 — same empty-assistant filter as streaming().
            if ('assistant' === $role && $this->history->isEmptyAssistantContent($messageContent)) {
                continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => $messageContent,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $this->bufferedCurrentTurn($systemPrompt, $currentMessage, $options, $generatedImages, $includeImages),
        ];

        return $messages;
    }

    /**
     * Build messages for streaming (TEXT only, no JSON)
     * Like old system: topicPrompt with $stream = true.
     */
    public function streaming(?string $systemPrompt, array $thread, Message $currentMessage, array $options = []): array
    {
        $messages = [];

        // Check if we should include images (only if vision model is available)
        $includeImages = $options['include_images'] ?? false;
        $generatedImages = $this->vision->generatedImagesForVision($currentMessage, $thread, $options);
        $mediaReferences = $this->history->mediaReferences($currentMessage, $thread);

        // Add system message if supported (o1 models don't support it)
        if (null !== $systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        // Add thread messages (last N messages)
        // IMPORTANT: Exclude the current message from the thread to avoid duplicates
        foreach ($thread as $msg) {
            // Skip if this is the current message (already added at the end).
            // Compare object identity first: transient incognito messages all
            // have a null id, and `null === null` would otherwise drop the
            // ENTIRE request-supplied history from the prompt.
            if ($msg === $currentMessage || (null !== $msg->getId() && $msg->getId() === $currentMessage->getId())) {
                continue;
            }

            $role = 'IN' === $msg->getDirection() ? 'user' : 'assistant';
            $content = $this->history->humanizeFileMarkers($msg->getText());

            // Include the extracted file text, legacy single file and attached files alike.
            $allFilesText = $msg->getAllFilesText(); // Combines legacy + file texts
            if (!empty($allFilesText)) {
                $fileInfo = '';
                if ($msg->getFiles()->count() > 0) {
                    $fileInfo = $msg->getFiles()->count().' file(s)';
                } elseif ($msg->getFileType()) {
                    $fileInfo = $msg->getFileType().' file';
                }

                // Role-aware label: for assistant turns this is the file the
                // assistant generated earlier, so present it as the current
                // document the model can transform when the user asks for edits.
                $label = 'assistant' === $role
                    ? "Current content of the file you previously generated ($fileInfo):"
                    : "User provided $fileInfo:";

                $content .= "\n\n\n---\n\n\n$label\n\n".
                           substr($allFilesText, 0, 10000). // Increased limit for multiple files
                           "\n\n";
            }

            // Generated image/video/audio has no file text, so without this the
            // assistant turn looks text-only and the model denies the media
            // exists on a follow-up (#1596).
            if ('assistant' === $role && isset($mediaReferences[$msg->getId()])) {
                $content = '' === trim($content)
                    ? $mediaReferences[$msg->getId()]
                    : $content."\n\n".$mediaReferences[$msg->getId()];
            }

            // For user messages, include images as multimodal content (only if enabled)
            if ('user' === $role && $includeImages) {
                $imageUrls = $this->vision->imageDataUrls($msg);
                $messageContent = $this->buildMultimodalContent($content, $imageUrls);
            } else {
                $messageContent = $content;
            }

            // #1115: Mistral rejects assistant turns with empty content
            // ("Assistant message must have either content or tool_calls") and
            // openai-php silently yields 0 stream chunks — which we then persist
            // as another empty OUT, poisoning the chat forever. Skip empty
            // assistant history turns for every provider (harmless elsewhere).
            if ('assistant' === $role && $this->history->isEmptyAssistantContent($messageContent)) {
                continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => $messageContent,
            ];
        }

        $messageContent = $this->buildCurrentMessageContent(
            $currentMessage,
            $includeImages,
            $options,
            $generatedImages['urls'],
            $generatedImages['notice'],
        );

        $messages[] = [
            'role' => 'user',
            'content' => $messageContent,
        ];

        return $messages;
    }

    /**
     * Build the content for the current user message (files, search results, images).
     *
     * @param list<string> $extraImageUrls generated-image data URLs to attach
     *                                     to this user turn (Anthropic rejects
     *                                     image blocks on assistant turns)
     * @param string       $extraImageNote provenance sentence for those images,
     *                                     see VisionAttachmentEncoder::generatedImagesForVision()
     *
     * @return string|array Content string or multimodal array when images are included
     */
    private function buildCurrentMessageContent(
        Message $currentMessage,
        bool $includeImages,
        array $options = [],
        array $extraImageUrls = [],
        string $extraImageNote = '',
    ): string|array {
        $content = $currentMessage->getText();
        $allFilesText = $currentMessage->getAllFilesText();

        $this->logger->debug('ChatHandler: File text debug', [
            'message_id' => $currentMessage->getId(),
            'has_legacy_file' => $currentMessage->getFile() > 0,
            'legacy_file_text_length' => strlen($currentMessage->getFileText() ?? ''),
            'files_collection_count' => $currentMessage->getFiles()->count(),
            'all_files_text_length' => strlen($allFilesText),
        ]);

        if (!empty($allFilesText)) {
            $fileInfo = '';
            if ($currentMessage->getFiles()->count() > 0) {
                $fileInfo = $currentMessage->getFiles()->count().' file(s)';
            } elseif ($currentMessage->getFileType()) {
                $fileInfo = $currentMessage->getFileType().' file';
            }

            // Fitted to the answering model's window by fitAttachmentText();
            // the flat 10 000-char cut is only the fallback without a budget.
            $attachmentText = is_string($options['attachment_text'] ?? null)
                ? $options['attachment_text']
                : substr($allFilesText, 0, self::LEGACY_ATTACHMENT_CHARS);

            $content .= "\n\n\n---\n\n\nUser provided $fileInfo:\n\n".
                       $attachmentText.
                       "\n\n";
        }

        // Fallback only: ChatHandler::handleStream()/handle() move search results into the
        // system prompt and unset this option. It is still set here solely for
        // models without system-message support (issue #1067).
        if (isset($options['search_results']) && !empty($options['search_results']['results'])) {
            $searchContext = ChatPromptBlocks::webSearchResults($options['search_results']);
            $content .= "\n\n".$searchContext;
        }

        // Fallback only: same rationale as search results above. ChatHandler::handleStream()
        // unsets the quote once it lands in the system prompt, so this branch
        // fires solely for models without system-message support.
        if (!empty($options['quoted_text'])) {
            $content .= "\n\n".ChatPromptBlocks::quotedReference($options);
        }

        $imageUrls = $includeImages ? $this->vision->imageDataUrls($currentMessage) : [];

        // Every attached image failed conversion (unreadable, or too large
        // even after downscaling). Fail loudly instead of sending a
        // text-only request the model would answer with "I see no image".
        if ($includeImages && [] === $imageUrls && $this->vision->hasAttachedImages($currentMessage)) {
            throw new VisionImageUnprocessableException();
        }

        if ([] !== $extraImageUrls) {
            $imageUrls = array_merge($imageUrls, $extraImageUrls);
            if ('' !== $extraImageNote) {
                $content .= "\n\n".$extraImageNote;
            }
        }

        if ([] !== $imageUrls) {
            return $this->buildMultimodalContent($content, $imageUrls);
        }

        return $content;
    }

    /**
     * Build multimodal content array with text and images.
     *
     * @return array|string Content as multimodal array or plain string if no images
     */
    private function buildMultimodalContent(string $textContent, array $imageUrls): array|string
    {
        if (empty($imageUrls)) {
            return $textContent;
        }

        // Build multimodal content array
        $content = [];

        // Add text first
        if (!empty($textContent)) {
            $content[] = [
                'type' => 'text',
                'text' => $textContent,
            ];
        }

        // Add images
        foreach ($imageUrls as $imageUrl) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageUrl,
                ],
            ];
        }

        $this->logger->info('ChatHandler: Built multimodal content', [
            'text_length' => strlen($textContent),
            'image_count' => count($imageUrls),
        ]);

        return $content;
    }

    /**
     * Attachment text of the current message fitted to the answering model's
     * context window (question-aware condensing when it does not fit — see
     * ContextCondenser). Returns null when there is no attachment text or no
     * budget service is wired, in which case the callers keep the legacy cut.
     */
    public function fitAttachmentText(Message $message, ?int $modelId, ?int $reservedOutputTokens): ?string
    {
        if (null === $this->contextCondenser || null === $this->modelContextWindow) {
            return null;
        }

        $text = $message->getAllFilesText();
        if ('' === trim($text)) {
            return null;
        }

        try {
            $budget = $this->modelContextWindow->attachmentCharBudget($modelId, $text, $message->getUserId(), $reservedOutputTokens);
            $fitted = $this->contextCondenser->fit($text, $message->getText(), $budget, $message->getUserId());
        } catch (\Throwable $e) {
            $this->logger->warning('ChatHandler: attachment fitting failed, using legacy cut', ['error' => $e->getMessage()]);

            return null;
        }

        if (!$fitted->isVerbatim()) {
            $this->logger->info('ChatHandler: attachment fitted to model window', $fitted->toLogContext() + ['model_id' => $modelId]);
        }

        $note = $fitted->provenanceNote();

        return null !== $note ? $note."\n\n".$fitted->text : $fitted->text;
    }

    /**
     * Find the OpenAI response_id from the last assistant message in the thread.
     *
     * Used for stateful conversations via the Responses API previous_response_id parameter.
     * Only returns an ID when the last assistant message was generated by OpenAI.
     */
    public function previousResponseId(array $thread): ?string
    {
        foreach (array_reverse($thread) as $msg) {
            if ('OUT' === $msg->getDirection()) {
                $responseId = $msg->getMeta('openai_response_id');
                if (null !== $responseId && '' !== $responseId) {
                    return $responseId;
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Decide whether the model accepts a `system` role message.
     *
     * Resolution order:
     *   1. Honour an explicit `supportsSystemMessages` flag in the model JSON.
     *   2. Fall back to the legacy convention where `supportsStreaming === false`
     *      also implied "no system messages" (true for OpenAI o1 reasoning models).
     *   3. Default to `true` so well-behaved providers keep working unchanged.
     *
     * @param array<string, mixed> $modelJson Raw model JSON config from `Model::getJson()`
     */
    public function supportsSystemMessages(array $modelJson): bool
    {
        if (array_key_exists('supportsSystemMessages', $modelJson)) {
            return false !== $modelJson['supportsSystemMessages'];
        }

        if (array_key_exists('supportsStreaming', $modelJson) && false === $modelJson['supportsStreaming']) {
            return false;
        }

        return true;
    }

    /**
     * Fallback for models that reject the `system` role: fold the system
     * prompt into the FIRST user turn so the instructions still reach the
     * model. Before this, the prompt was silently dropped, which discarded
     * the entire topic prompt (officemaker's JSON file contract, RAG context,
     * language directive, …) and produced free-form answers instead of
     * generated documents (GPT-5.5 Pro officemaker bug).
     *
     * @param list<array{role: string, content: string|array<int, mixed>}> $messages
     *
     * @return list<array{role: string, content: string|array<int, mixed>}>
     */
    public function prependSystemPrompt(array $messages, string $systemPrompt): array
    {
        if ('' === trim($systemPrompt)) {
            return $messages;
        }

        foreach ($messages as $i => $msg) {
            if ('user' !== $msg['role']) {
                continue;
            }

            $content = $msg['content'];
            if (is_string($content)) {
                $messages[$i]['content'] = $systemPrompt."\n\n---\n\n".$content;
            } elseif (is_array($content)) {
                // Multimodal content: prepend to the first text part, or add
                // a text part if the turn is image-only.
                $prepended = false;
                foreach ($content as $j => $part) {
                    if (is_array($part) && 'text' === ($part['type'] ?? null) && is_string($part['text'] ?? null)) {
                        $content[$j]['text'] = $systemPrompt."\n\n---\n\n".$part['text'];
                        $prepended = true;
                        break;
                    }
                }
                if (!$prepended) {
                    array_unshift($content, ['type' => 'text', 'text' => $systemPrompt]);
                }
                $messages[$i]['content'] = $content;
            }

            return $messages;
        }

        return $messages;
    }

    /**
     * The current turn of a buffered channel: the legacy BTEXT JSON envelope,
     * with the context a model without a system role cannot get elsewhere
     * and the images a vision model should see.
     *
     * @param array<string, mixed>                      $options
     * @param array{urls: list<string>, notice: string} $generatedImages
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function bufferedCurrentTurn(?string $systemPrompt, Message $currentMessage, array $options, array $generatedImages, bool $includeImages): string|array
    {
        $msgArr = [
            'BUNIXTIMES' => $currentMessage->getUnixTimestamp(),
            'BDATETIME' => $currentMessage->getDateTime(),
            'BFILEPATH' => $currentMessage->getFilePath() ?: '',
            'BFILETYPE' => $currentMessage->getFileType() ?: '',
            'BTOPIC' => $currentMessage->getTopic(),
            'BLANG' => $currentMessage->getLanguage(),
            'BTEXT' => $currentMessage->getText(),
            'BFILETEXT' => is_string($options['attachment_text'] ?? null)
                ? $options['attachment_text']
                : ($currentMessage->getFileText() ?: ''),
        ];

        $ragContext = $options['rag_context'] ?? '';
        if (null === $systemPrompt && !empty($ragContext)) {
            $msgArr['BTEXT'] .= "\n\n".trim($ragContext);
        }

        // Fallback only: ChatHandler::handle() moves search results into the system prompt
        // and clears this option for models with system-message support (#1067).
        if (isset($options['search_results']) && !empty($options['search_results']['results'])) {
            $searchContext = ChatPromptBlocks::webSearchResults($options['search_results']);
            $msgArr['BTEXT'] .= "\n\n".$searchContext;

            $this->logger->info('ChatHandler: Web search results appended to BTEXT', [
                'results_count' => count($options['search_results']['results']),
                'query' => $options['search_results']['query'] ?? '',
            ]);
        }

        // Fallback only: ChatHandler::handle() injects the quote into the system prompt and
        // clears this option for models with system-message support. Models
        // without it (e.g. o1) keep the quote here on the user turn.
        if (!empty($options['quoted_text'])) {
            $msgArr['BTEXT'] .= "\n\n".ChatPromptBlocks::quotedReference($options);
        }

        // The provenance sentence has to go INTO the JSON body: the text part of
        // this turn is the encoded $msgArr, so appending after json_encode()
        // would hand the model a payload it can no longer parse.
        if ([] !== $generatedImages['urls'] && '' !== $generatedImages['notice']) {
            $msgArr['BTEXT'] .= "\n\n".$generatedImages['notice'];
        }

        // Extract images from current message for vision support (only if enabled)
        $textContent = json_encode($msgArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $imageUrls = $includeImages ? $this->vision->imageDataUrls($currentMessage) : [];
        $imageUrls = array_merge($imageUrls, $generatedImages['urls']);
        if ([] !== $imageUrls) {
            $messageContent = $this->buildMultimodalContent($textContent, $imageUrls);

            $this->logger->info('ChatHandler: Images included for vision (non-streaming)', [
                'message_id' => $currentMessage->getId(),
                'image_count' => count($imageUrls),
            ]);
        } else {
            $messageContent = $textContent;
        }

        return $messageContent;
    }
}
