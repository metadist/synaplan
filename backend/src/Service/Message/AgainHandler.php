<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * AgainHandler.
 *
 * Handles "Again" requests where a user wants to re-process
 * their message with a different AI model.
 *
 * This keeps the MessageController clean and separates concerns.
 */
class AgainHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageProcessor $messageProcessor,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Process an "Again" request.
     *
     * @param User  $user Current user
     * @param array $data Request data with originalMessageId, modelId, promptId
     *
     * @return array Response data
     *
     * @throws \Exception On processing errors
     */
    public function processAgainRequest(User $user, array $data): array
    {
        $originalMessageId = $data['originalMessageId'] ?? null;
        $modelId = $data['modelId'] ?? null;
        $promptId = $data['promptId'] ?? null;

        if (!$originalMessageId) {
            throw new \InvalidArgumentException('Original message ID is required');
        }

        // Get original incoming message
        $originalMessage = $this->em->getRepository(Message::class)->find($originalMessageId);

        if (!$originalMessage || $originalMessage->getUserId() !== $user->getId()) {
            throw new \RuntimeException('Original message not found or access denied');
        }

        $mediaPrompt = $originalMessage->getMeta('media_prompt');
        $mediaType = $originalMessage->getMeta('media_type');

        // Create new incoming message with same content (or media prompt override)
        $incomingMessage = $this->createIncomingMessage($user, $originalMessage, $mediaPrompt, $mediaType);

        $this->em->persist($incomingMessage);
        $this->em->flush();

        $this->logger->info('AgainHandler: Message persisted and flushed', [
            'message_id' => $incomingMessage->getId(),
            'has_id' => null !== $incomingMessage->getId(),
        ]);

        // Set metadata for skipping sorting
        $this->setMessageMetadata($incomingMessage, $promptId, $modelId);

        $processingResult = $this->runMessagePipeline($incomingMessage);
        $classification = $processingResult['classification'] ?? [];
        $handlerResponse = $processingResult['response'] ?? [];
        $streamedText = $processingResult['text'] ?? $incomingMessage->getText();

        // Create outgoing message with handler metadata
        $incomingMessage->setTopic($classification['topic'] ?? $incomingMessage->getTopic());
        $incomingMessage->setLanguage($classification['language'] ?? $incomingMessage->getLanguage());

        $outgoingMessage = $this->createOutgoingMessageFromProcessing(
            $user,
            $incomingMessage,
            $streamedText,
            $classification,
            $handlerResponse,
            $modelId
        );

        $this->em->persist($outgoingMessage);
        $incomingMessage->setStatus('complete');
        $this->em->flush();

        $this->logger->info('Again request processed', [
            'user_id' => $user->getId(),
            'original_message_id' => $originalMessageId,
            'new_message_id' => $outgoingMessage->getId(),
            'model_id' => $modelId,
        ]);

        return [
            'success' => true,
            'message' => [
                'id' => $outgoingMessage->getId(),
                'text' => $outgoingMessage->getText(),
                'hasFile' => (bool) $outgoingMessage->getFile(),
                'filePath' => $outgoingMessage->getFilePath(),
                'fileType' => $outgoingMessage->getFileType(),
                'provider' => $outgoingMessage->getProviderIndex(),
                'timestamp' => $outgoingMessage->getUnixTimestamp(),
                'trackId' => $outgoingMessage->getTrackingId(),
                'topic' => $incomingMessage->getTopic(),
            ],
        ];
    }

    /**
     * Create incoming message clone.
     */
    private function createIncomingMessage(
        User $user,
        Message $originalMessage,
        ?string $mediaPrompt = null,
        ?string $mediaType = null,
    ): Message {
        $message = new Message();
        $message->setUserId($user->getId());
        $message->setTrackingId($originalMessage->getTrackingId());
        if ($originalMessage->getChat()) {
            $message->setChat($originalMessage->getChat());
        }
        $message->setChatId($originalMessage->getChatId());
        $message->setProviderIndex('WEB');
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('WEB');
        $message->setFile($originalMessage->getFile());
        $message->setFilePath($originalMessage->getFilePath());
        $message->setFileType($originalMessage->getFileType());
        $message->setTopic($originalMessage->getTopic());
        $message->setLanguage($originalMessage->getLanguage());
        $message->setText($originalMessage->getText());
        $message->setDirection('IN');
        $message->setStatus('processing');

        if ($mediaPrompt && 'audio' === $mediaType) {
            $message->setText($mediaPrompt);
            $message->setTopic('mediamaker');
            $message->setMeta('media_prompt_override', $mediaPrompt);
            $message->setMeta('media_type', $mediaType);
        }

        return $message;
    }

    /**
     * Run message through the normal pipeline (streaming mode) and capture chunks.
     */
    private function runMessagePipeline(Message $message): array
    {
        $buffer = '';

        $streamCallback = function ($chunk) use (&$buffer) {
            if (is_array($chunk)) {
                if (($chunk['type'] ?? '') === 'content' && isset($chunk['content'])) {
                    $buffer .= $chunk['content'];
                } elseif (isset($chunk['message'])) {
                    $buffer .= (string) $chunk['message'];
                } elseif (isset($chunk['content'])) {
                    $buffer .= (string) $chunk['content'];
                }
            } else {
                $buffer .= (string) $chunk;
            }
        };

        $statusCallback = function (array $status) {
            // Optional: log status updates for debugging
        };

        $result = $this->messageProcessor->processStream(
            $message,
            $streamCallback,
            $statusCallback,
            [] // rely on MessageMeta overrides for Again
        );

        if (!($result['success'] ?? false)) {
            $error = $result['error'] ?? 'Unknown processing error';
            throw new \RuntimeException($error);
        }

        $result['text'] = trim($buffer);

        return $result;
    }

    /**
     * Set message metadata to skip sorting.
     */
    private function setMessageMetadata(Message $message, ?string $promptId, ?int $modelId): void
    {
        $this->logger->info('AgainHandler: setMessageMetadata called', [
            'message_id' => $message->getId(),
            'has_id' => null !== $message->getId(),
            'prompt_id' => $promptId,
            'model_id' => $modelId,
        ]);

        // Message must be flushed before creating MessageMeta (needs message ID)
        if (!$message->getId()) {
            $this->logger->error('AgainHandler: Message has no ID!', [
                'message' => $message,
            ]);
            throw new \LogicException('Message must be persisted and flushed before setting metadata');
        }

        // Set PROMPTID in MessageMeta to skip sorting
        if ($promptId) {
            $meta = new MessageMeta();
            $meta->setMessage($message); // Use setMessage() instead of setMessageId()
            $meta->setMetaKey('PROMPTID');
            $meta->setMetaValue($promptId);
            $this->em->persist($meta);
            $this->logger->info('AgainHandler: PROMPTID meta created');
        }

        // Set MODEL_ID in MessageMeta if specific model requested
        if ($modelId) {
            $meta = new MessageMeta();
            $meta->setMessage($message); // Use setMessage() instead of setMessageId()
            $meta->setMetaKey('MODEL_ID');
            $meta->setMetaValue((string) $modelId);
            $this->em->persist($meta);
            $this->logger->info('AgainHandler: MODEL_ID meta created');
        }

        $this->em->flush();
        $this->logger->info('AgainHandler: Metadata flushed');
    }

    private function createOutgoingMessageFromProcessing(
        User $user,
        Message $incomingMessage,
        string $responseText,
        array $classification,
        array $handlerResponse,
        ?int $selectedModelId,
    ): Message {
        $metadata = $handlerResponse['metadata'] ?? [];
        $fileMeta = $metadata['file'] ?? null;

        $hasFile = $fileMeta ? 1 : 0;
        $filePath = $fileMeta['path'] ?? '';
        $fileType = $fileMeta['type'] ?? '';

        if (!$hasFile) {
            [$markerHasFile, $markerPath, $markerType, $cleanText] = $this->parseMediaMarkers($responseText);
            if ($markerHasFile) {
                $hasFile = 1;
                $filePath = $markerPath;
                $fileType = $markerType;
                $responseText = $cleanText;
            }
        }

        $message = new Message();
        $message->setUserId($user->getId());
        $message->setTrackingId($incomingMessage->getTrackingId());
        $message->setChat($incomingMessage->getChat());
        $message->setProviderIndex($metadata['provider'] ?? $incomingMessage->getProviderIndex());
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('WEB');
        $message->setFile($hasFile);
        $message->setFilePath($filePath);
        $message->setFileType($fileType);
        $message->setTopic($classification['topic'] ?? $incomingMessage->getTopic());
        $message->setLanguage($classification['language'] ?? $incomingMessage->getLanguage());
        $message->setText(trim($responseText));
        $message->setDirection('OUT');
        $message->setStatus('complete');

        // Store metadata similar to streaming flow
        $message->setMeta('ai_chat_provider', $metadata['provider'] ?? 'unknown');
        $message->setMeta('ai_chat_model', $metadata['model'] ?? 'unknown');

        if ($selectedModelId) {
            $message->setMeta('ai_chat_model_id', (string) $selectedModelId);
        } elseif (!empty($metadata['model_id'])) {
            $message->setMeta('ai_chat_model_id', (string) $metadata['model_id']);
        }

        if (!empty($metadata['usage'])) {
            $message->setMeta('ai_chat_usage', json_encode($metadata['usage']));
        }

        if (!empty($classification['sorting_provider'])) {
            $message->setMeta('ai_sorting_provider', $classification['sorting_provider']);
        }
        if (!empty($classification['sorting_model_name'])) {
            $message->setMeta('ai_sorting_model', $classification['sorting_model_name']);
        }
        if (!empty($classification['sorting_model_id'])) {
            $message->setMeta('ai_sorting_model_id', (string) $classification['sorting_model_id']);
        }

        return $message;
    }

    /**
     * Parse media markers like [IMAGE:url] or [VIDEO:url].
     */
    private function parseMediaMarkers(string $text): array
    {
        $hasFile = false;
        $filePath = '';
        $fileType = '';
        $cleanText = $text;

        if (preg_match('/\[IMAGE:(.*?)]/i', $text, $matches)) {
            $filePath = $matches[1];
            $fileType = 'png';
            $hasFile = true;
            $cleanText = str_replace($matches[0], '', $text);
        } elseif (preg_match('/\[VIDEO:(.*?)]/i', $text, $matches)) {
            $filePath = $matches[1];
            $fileType = 'mp4';
            $hasFile = true;
            $cleanText = str_replace($matches[0], '', $text);
        }

        return [$hasFile, $filePath, $fileType, $cleanText];
    }
}
