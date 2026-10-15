<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Service\Message\MessageForwardingService;
use App\Service\Realtime\SseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets a streamed turn up before the model runs: the chat, the answer to
 * continue, the quoted source, the incoming message with its files, and the
 * processing options for the message pipeline.
 *
 * An incognito turn stays in memory: no chat is loaded and the incoming
 * message is never persisted, so every write below is guarded.
 */
final readonly class StreamTurnPreparer
{
    private const CONTINUATION_REQUEST = 'Continue your previous response.';

    public function __construct(
        private EntityManagerInterface $em,
        private MessageForwardingService $messageForwardingService,
        private StreamMessageMeta $meta,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool false when the turn cannot start; the client already got the error event
     */
    public function prepare(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state): bool
    {
        if (!$this->loadChat($sse, $turn, $state) || !$this->loadContinued($sse, $turn, $state)) {
            return false;
        }

        $state->quotedMessageId = $this->provenQuoteSource($turn);
        $this->createIncoming($turn, $state);

        // #1024: the operator's prompt goes to WhatsApp before the answer, so
        // the whole exchange shows there. Not for widget or guest callers, the
        // synthetic continuation, an "Again" (same prompt twice) or incognito
        // (no chat).
        $caller = $turn->caller;
        if (!$caller->isWidget() && !$caller->isGuest() && !$turn->input->continueMessageId && !$turn->options->isAgain && null !== $state->chat) {
            $this->messageForwardingService->forwardUserPromptIfNeeded($state->chat, $state->messageText);
        }

        return $this->attachFiles($sse, $turn, $state);
    }

    /**
     * @return array<string, mixed>
     */
    public function processingOptions(StreamTurnRequest $turn, StreamTurnState $state): array
    {
        $options = $turn->options;
        $processingOptions = [
            'reasoning' => $options->reasoning,
            ...('' !== $options->reasoningEffort ? ['reasoning_effort' => $options->reasoningEffort] : []),
            'web_search' => $options->webSearch,
            'voice_reply' => $options->voiceReply,
            'is_continuation' => (bool) $turn->input->continueMessageId,
            'perf_timer' => $state->perfTimer,
            // #881: the memory job is dispatched after the reply is stored;
            // ChatHandler hands it back in `metadata.extraction_payload`.
            'defer_memory_extraction' => true,
            'client_country' => $turn->caller->clientCountry,
            // Lets a long media render be cancelled from another worker.
            'track_id' => (string) $turn->trackId,
            // #1146: the media handler bills a render the moment the provider
            // does, so a disconnect cannot skip it (`usage_recorded`).
            'record_media_usage' => true,
        ];

        if (null !== $options->agent) {
            $processingOptions['agentId'] = $options->agent->agentId;
            if ($options->agent->draft) {
                $processingOptions['agentDraft'] = true;
            }
            if (null !== $options->agent->versionId) {
                $processingOptions['agentVersionId'] = $options->agent->versionId;
            }
        }

        $quote = $turn->input->quote;
        if (null !== $quote) {
            $processingOptions['quoted_text'] = $quote->text;
            if (null !== $state->quotedMessageId) {
                $processingOptions['quoted_message_id'] = $state->quotedMessageId;
            }
        }

        if ($turn->caller->isWidget() || $turn->caller->isGuest() || $options->disableMemories) {
            $processingOptions['disable_memories'] = true;
        }

        if ($turn->incognito) {
            // Persistence is guarded throughout the pipeline while memory,
            // knowledge and feedback reads stay on — not `disable_memories`.
            // Media stays inline so it can be tracked as ephemeral.
            $processingOptions['incognito'] = true;
            $processingOptions['incognito_history'] = $turn->incognitoHistory;
            $processingOptions['force_inline_media'] = true;
        }

        return $this->withModelAndPrompt($processingOptions, $turn);
    }

    private function loadChat(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state): bool
    {
        if (!$turn->incognito) {
            $chat = $this->em->getRepository(Chat::class)->find((int) $turn->chatId);
            if (!$chat || $chat->getUserId() !== $turn->caller->user->getId()) {
                $sse->send('error', ['error' => 'Chat not found or access denied']);

                return false;
            }
            $state->chat = $chat;
        }

        // A guest may only write into the chat of their own session.
        $guestSession = $turn->caller->guestSession;
        if (null !== $guestSession) {
            $sessionChatId = $guestSession->getChatId();
            if (null === $sessionChatId || $sessionChatId !== (int) $turn->chatId) {
                $sse->send('error', ['error' => 'Chat does not belong to this guest session']);

                return false;
            }
        }

        return true;
    }

    private function loadContinued(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state): bool
    {
        $continueMessageId = $turn->input->continueMessageId;
        if (!$continueMessageId) {
            return true;
        }

        $original = $this->em->getRepository(Message::class)->find((int) $continueMessageId);
        if (!$original
            || $original->getUserId() !== $turn->caller->user->getId()
            || $original->getChatId() !== (int) $turn->chatId
            || 'OUT' !== $original->getDirection()
        ) {
            $sse->send('error', ['error' => 'Original message not found']);

            return false;
        }

        $state->continued = $original;
        $state->messageText = self::CONTINUATION_REQUEST;

        return true;
    }

    /**
     * The quoted source counts only when it belongs to the same chat and
     * person; otherwise just the quoted text is used.
     */
    private function provenQuoteSource(StreamTurnRequest $turn): ?int
    {
        $quote = $turn->input->quote;
        if (null === $quote || null === $quote->messageId) {
            return null;
        }

        $source = $this->em->getRepository(Message::class)->find($quote->messageId);
        if ($source
            && $source->getUserId() === $turn->caller->user->getId()
            && $source->getChatId() === (int) $turn->chatId
        ) {
            return $quote->messageId;
        }

        return null;
    }

    private function createIncoming(StreamTurnRequest $turn, StreamTurnState $state): void
    {
        $continued = (bool) $turn->input->continueMessageId;

        $incoming = new Message();
        $incoming->setUserId($turn->caller->user->getId());
        $incoming->setChat($state->chat);
        $incoming->setTrackingId((int) $turn->trackId);
        $incoming->setProviderIndex('WEB');
        $incoming->setUnixTimestamp(time());
        $incoming->setDateTime(date('YmdHis'));
        $incoming->setMessageType('WEB');
        $incoming->setFile(0);
        $incoming->setTopic($continued ? 'CONTINUE' : 'CHAT');
        $incoming->setLanguage($state->continued ? $state->continued->getLanguage() : $turn->caller->uiLanguage);
        $incoming->setText($state->messageText);
        $incoming->setDirection('IN');
        $incoming->setStatus($continued ? 'hidden' : 'processing');
        $state->incoming = $incoming;

        // Incognito keeps the message transient: no id, and every setMeta()
        // and addFile() below stays in memory.
        if (!$turn->incognito) {
            $this->em->persist($incoming);
            $this->em->flush();
        }

        // The quote survives a reload as a blockquote in the person's bubble;
        // flushed before the file reload below detaches everything.
        $quote = $turn->input->quote;
        if (null !== $quote) {
            $incoming->setMeta('quoted_text', $quote->text);
            if (null !== $state->quotedMessageId) {
                $incoming->setMeta('quoted_message_id', (string) $state->quotedMessageId);
            }
            if (!$turn->incognito) {
                $this->em->flush();
            }
        }

        $agent = $turn->options->agent;
        $this->meta->stampAgentId($incoming, $agent?->agentId, $agent?->versionId);
        if (null !== $agent && !$turn->incognito) {
            $this->em->flush();
        }
    }

    /**
     * Files of any status (uploaded, extracted, vectorized) the person owns.
     * A persisted message is flushed and reloaded so its file collection is
     * fresh — refresh() is not reliable for collections.
     */
    private function attachFiles(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state): bool
    {
        $incoming = $state->incoming();
        $fileCount = 0;
        foreach ($turn->input->fileIds as $fileId) {
            $file = $this->em->getRepository(File::class)->find($fileId);
            if ($file && $file->getUserId() === $turn->caller->user->getId()) {
                $incoming->addFile($file);
                $file->keepAfterChatSend($turn->incognito);
                ++$fileCount;

                $this->logger->info('StreamController: File attached to message', [
                    'message_id' => $incoming->getId(),
                    'file_id' => $fileId,
                    'file_path' => $file->getFilePath(),
                    'file_type' => $file->getFileType(),
                    'file_status' => $file->getStatus(),
                ]);
            }
        }

        if (0 === $fileCount) {
            return true;
        }

        // Legacy file flag.
        $incoming->setFile($fileCount);
        if (!$turn->incognito && !$this->reloadWithFiles($sse, $turn, $state)) {
            return false;
        }

        $this->logger->info('StreamController: Files attached and entity reloaded', [
            'message_id' => $state->incoming()->getId(),
            'files_count' => $state->incoming()->getFiles()->count(),
        ]);

        $sse->send('status', [
            'message' => "Processing $fileCount file(s)...",
            'stage' => 'preprocessing',
            'file_count' => $fileCount,
        ]);

        return true;
    }

    private function reloadWithFiles(SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state): bool
    {
        $this->em->flush();

        $messageId = $state->incoming()->getId();
        $state->chatId = $state->incoming()->getChatId();

        $this->em->clear();

        $state->incoming = $this->em->getRepository(Message::class)->find($messageId);
        if (!$state->incoming) {
            $this->logger->error('StreamController: Message not found after refresh!', [
                'message_id' => $messageId,
            ]);
            $sse->send('error', ['message' => 'Internal error: Message lost']);

            return false;
        }

        // A reloaded chat avoids a cascade-persist error.
        if ($state->chatId) {
            $state->chat = $this->em->getRepository(Chat::class)->find($state->chatId);
            if ($state->chat) {
                $state->incoming->setChat($state->chat);
            }
        }

        // The answer to continue has to stay managed too.
        if ($state->continued) {
            $state->continued = $this->em->getRepository(Message::class)->find((int) $turn->input->continueMessageId);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $processingOptions
     *
     * @return array<string, mixed>
     */
    private function withModelAndPrompt(array $processingOptions, StreamTurnRequest $turn): array
    {
        $options = $turn->options;
        if ($options->modelId) {
            if ($options->isAgain) {
                $processingOptions['model_id'] = (int) $options->modelId;
                $processingOptions['is_again'] = true;
            } else {
                $processingOptions['override_model_id'] = (int) $options->modelId;
            }
            $this->logger->info('StreamController: Model specified', [
                'model_id' => $options->modelId,
                'is_again' => $options->isAgain,
            ]);
        }

        $isWidget = $turn->caller->isWidget();
        $fixedTaskPrompt = $options->fixedTaskPromptTopic;
        if ($isWidget) {
            // A widget answers with its own task prompt; no sorting.
            $processingOptions['is_widget_mode'] = true;
            if ($fixedTaskPrompt) {
                $processingOptions['fixed_task_prompt'] = $fixedTaskPrompt;
                $this->logger->info('StreamController: Using fixed task prompt for widget', [
                    'task_prompt' => $fixedTaskPrompt,
                ]);
            }
        } elseif ($fixedTaskPrompt) {
            $processingOptions['fixed_task_prompt'] = $fixedTaskPrompt;
            $this->logger->info('StreamController: Using API-selected task prompt', [
                'task_prompt' => $fixedTaskPrompt,
            ]);
        }

        return $this->withRagGroupKey($processingOptions, $isWidget, $options->ragGroupKey);
    }

    /**
     * The knowledge-base folder picked in the composer. A widget never
     * honours a caller-supplied key: it is locked to its own configuration,
     * so an embedding page cannot widen or redirect retrieval.
     *
     * @param array<string, mixed> $processingOptions
     *
     * @return array<string, mixed>
     */
    private function withRagGroupKey(array $processingOptions, bool $isWidgetMode, ?string $ragGroupKey): array
    {
        if ($isWidgetMode || empty($ragGroupKey)) {
            return $processingOptions;
        }

        $processingOptions['rag_group_key'] = $ragGroupKey;
        $this->logger->info('StreamController: Scoping RAG to user-selected group', [
            'rag_group_key' => $ragGroupKey,
        ]);

        return $processingOptions;
    }
}
