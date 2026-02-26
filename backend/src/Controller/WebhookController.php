<?php

namespace App\Controller;

use App\AI\Service\AiFacade;
use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Message;
use App\Entity\User;
use App\Service\DiscordNotificationService;
use App\Service\EmailChatService;
use App\Service\EmailWebhookIdempotencyService;
use App\Service\InternalEmailService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\TtsTextSanitizer;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/webhooks', name: 'api_webhooks_')]
#[OA\Tag(name: 'Webhooks')]
class WebhookController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageProcessor $messageProcessor,
        private EmailWebhookIdempotencyService $emailWebhookIdempotencyService,
        private RateLimitService $rateLimitService,
        private WhatsAppService $whatsAppService,
        private EmailChatService $emailChatService,
        private InternalEmailService $internalEmailService,
        private DiscordNotificationService $discordNotificationService,
        private LoggerInterface $logger,
        private string $whatsappWebhookVerifyToken,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
    ) {
    }

    #[Route('/email', name: 'email', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/webhooks/email',
        summary: 'Email webhook endpoint',
        description: 'Handles incoming emails for processing by AI assistant. Authentication via API Key required.',
        tags: ['Webhooks']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['from', 'to', 'body'],
            properties: [
                new OA\Property(property: 'from', type: 'string', format: 'email', example: 'user@example.com'),
                new OA\Property(property: 'to', type: 'string', format: 'email', example: 'smart@synaplan.net', description: 'Can include keyword: smart+keyword@synaplan.net'),
                new OA\Property(property: 'subject', type: 'string', example: 'Question about AI'),
                new OA\Property(property: 'body', type: 'string', example: 'What is machine learning?'),
                new OA\Property(property: 'message_id', type: 'string', example: 'external-msg-123'),
                new OA\Property(property: 'in_reply_to', type: 'string', example: 'previous-msg-id', nullable: true),
                new OA\Property(
                    property: 'attachments',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'filename', type: 'string'),
                            new OA\Property(property: 'content_type', type: 'string'),
                            new OA\Property(property: 'size', type: 'integer'),
                            new OA\Property(property: 'url', type: 'string'),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Email processed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message_id', type: 'integer', example: 123),
                new OA\Property(property: 'chat_id', type: 'integer', example: 456),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid payload or missing fields')]
    #[OA\Response(response: 401, description: 'Invalid API key')]
    #[OA\Response(response: 429, description: 'Rate limit exceeded')]
    public function email(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate required fields
        if (empty($data['from']) || empty($data['to']) || empty($data['body'])) {
            return $this->json([
                'success' => false,
                'error' => 'Missing required fields: from, to, body',
            ], Response::HTTP_BAD_REQUEST);
        }

        $fromEmail = strtolower(trim((string) $data['from']));
        $toEmail = strtolower(trim((string) $data['to']));
        $subject = $data['subject'] ?? '(no subject)';
        $body = $data['body'];
        $messageId = $data['message_id'] ?? null;
        $inReplyTo = $data['in_reply_to'] ?? null;
        $idempotency = $this->emailWebhookIdempotencyService->findDuplicate(
            $fromEmail,
            $toEmail,
            $subject,
            $body,
            $messageId
        );
        $existingMessage = $idempotency['existing'];
        $emailFingerprint = $idempotency['fingerprint'];
        $normalizedMessageId = $idempotency['normalized_message_id'];

        // Parse keyword from to-address (smart+keyword@synaplan.net)
        $keyword = $this->emailChatService->parseEmailKeyword($toEmail);

        $this->logger->info('Email webhook received', [
            'from' => $fromEmail,
            'to' => $toEmail,
            'keyword' => $keyword,
            'subject' => $subject,
            'body_length' => strlen($body),
        ]);

        // Idempotency guard: skip retried webhook deliveries for already-processed emails.
        if ($normalizedMessageId) {
            if ($existingMessage) {
                $this->logger->warning('Duplicate email webhook detected; skipping processing', [
                    'external_message_id' => $normalizedMessageId,
                    'from' => $fromEmail,
                    'existing_message_id' => $existingMessage->getId(),
                ]);
                $this->discordNotificationService->notifyDuplicateEmailWebhook(
                    fromEmail: $fromEmail,
                    toEmail: $toEmail,
                    subject: $subject,
                    existingMessageId: $existingMessage->getId(),
                    chatId: $existingMessage->getChatId(),
                    externalMessageId: $normalizedMessageId,
                    detectionMethod: 'external_id'
                );

                return $this->json([
                    'success' => true,
                    'duplicate' => true,
                    'message_id' => $existingMessage->getId(),
                    'chat_id' => $existingMessage->getChatId(),
                ]);
            }
        } else {
            if ($existingMessage) {
                $this->logger->warning('Duplicate email webhook detected via fingerprint; skipping processing', [
                    'from' => $fromEmail,
                    'existing_message_id' => $existingMessage->getId(),
                ]);
                $this->discordNotificationService->notifyDuplicateEmailWebhook(
                    fromEmail: $fromEmail,
                    toEmail: $toEmail,
                    subject: $subject,
                    existingMessageId: $existingMessage->getId(),
                    chatId: $existingMessage->getChatId(),
                    externalMessageId: null,
                    detectionMethod: 'fingerprint'
                );

                return $this->json([
                    'success' => true,
                    'duplicate' => true,
                    'message_id' => $existingMessage->getId(),
                    'chat_id' => $existingMessage->getChatId(),
                ]);
            }
        }

        // Find or create user from email
        $userResult = $this->emailChatService->findOrCreateUserFromEmail($fromEmail);

        if (isset($userResult['error'])) {
            $this->logger->warning('Email rejected', [
                'email' => $fromEmail,
                'reason' => $userResult['error'],
            ]);

            return $this->json([
                'success' => false,
                'error' => $userResult['error'],
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $userResult['user'];

        // Check rate limit (unified across all sources)
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return $this->json([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'limit' => $rateLimitCheck['limit'],
                'used' => $rateLimitCheck['used'],
                'reset_at' => $rateLimitCheck['reset_at'] ?? null,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            // Find or create chat context
            $chat = $this->emailChatService->findOrCreateChatContext(
                $user,
                $keyword,
                $subject,
                $inReplyTo
            );

            // Create incoming message
            $message = new Message();
            $message->setUserId($user->getId());
            $message->setChatId($chat->getId());
            $message->setTrackingId(time());
            $message->setProviderIndex('EMAIL');
            $message->setUnixTimestamp(time());
            $message->setDateTime(date('YmdHis'));
            $message->setMessageType('MAIL');
            $message->setFile(0);
            $message->setTopic('CHAT');
            $message->setLanguage('en'); // Will be detected by classifier

            // Use subject as context if provided
            $messageText = $body;
            if (!empty($subject) && '(no subject)' !== $subject) {
                $messageText = 'Subject: '.$subject."\n\n".$messageText;
            }

            $message->setText($messageText);
            $message->setDirection('IN');
            $message->setStatus('processing');

            $this->em->persist($message);
            $this->em->flush(); // MUST flush before setMeta() to get message ID

            // Store email metadata
            $message->setMeta('channel', 'email');
            $message->setMeta('from_email', $fromEmail);
            $message->setMeta('to_email', $toEmail);
            $message->setMeta('email_fingerprint', $emailFingerprint);

            if ($keyword) {
                $message->setMeta('email_keyword', $keyword);
            }
            if (!empty($subject)) {
                $message->setMeta('email_subject', $subject);
            }
            if ($normalizedMessageId) {
                $message->setMeta('external_id', $normalizedMessageId);
                // Note: Email threading is handled via Chat titles (Email: keyword or Email Conversation)
            }
            if (!empty($data['attachments'])) {
                $message->setMeta('has_attachments', 'true');

                // Check for audio attachments to enable voice reply
                foreach ($data['attachments'] as $attachment) {
                    $mime = $attachment['content_type'] ?? '';
                    if (str_starts_with($mime, 'audio/')) {
                        $message->setMeta('voice_reply', '1');
                        break;
                    }
                }
            }
            $this->em->flush(); // Flush metadata

            // Track processing time
            $startTime = microtime(true);

            // Process message through pipeline
            $result = $this->messageProcessor->process($message);

            $processingTime = microtime(true) - $startTime;

            if (!$result['success']) {
                return $this->json([
                    'success' => false,
                    'error' => 'Message processing failed',
                    'details' => $result['error'] ?? 'Unknown error',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $aiResponse = $result['response'];
            $responseText = $aiResponse['content'] ?? '';
            $metadata = $aiResponse['metadata'] ?? [];
            $attachmentPath = $this->resolveAttachmentPathFromAiMetadata($metadata);

            // Extract provider and model from metadata
            $provider = $metadata['provider'] ?? null;
            $model = $metadata['model'] ?? null;

            // Record usage with response content for token estimation
            $this->rateLimitService->recordUsage($user, 'MESSAGES', [
                'provider' => $provider ?? 'unknown',
                'model' => $model ?? 'unknown',
                'tokens' => 0,
                'latency' => (int) ($processingTime * 1000),
                'source' => 'EMAIL',
                'response_text' => $responseText,
                'input_text' => $message->getText(),
            ]);

            // Generate TTS if voice_reply is set and no media attachment already exists.
            if (null === $attachmentPath && '1' === $message->getMeta('voice_reply')) {
                try {
                    $ttsText = TtsTextSanitizer::sanitize($responseText);
                    if (!empty(trim($ttsText))) {
                        $ttsModelId = $this->modelConfigService->getDefaultModel('TEXT2SOUND', $user->getId());
                        $ttsProvider = $ttsModelId ? $this->modelConfigService->getProviderForModel($ttsModelId) : null;

                        $ttsResult = $this->aiFacade->synthesize($ttsText, $user->getId(), [
                            'format' => 'mp3',
                            'provider' => $ttsProvider ? strtolower($ttsProvider) : null,
                        ]);
                        // Get absolute path for attachment
                        $attachmentPath = $this->getParameter('kernel.project_dir').'/var/uploads/'.$ttsResult['relativePath'];
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Failed to generate TTS for email', ['error' => $e->getMessage()]);
                }
            }

            // Send email response back to user
            try {
                $this->internalEmailService->sendAiResponseEmail(
                    $fromEmail,
                    $subject,
                    $responseText,
                    $messageId,
                    $provider,
                    $model,
                    $processingTime,
                    $attachmentPath
                );

                $this->logger->info('Email response sent', [
                    'to' => $fromEmail,
                    'subject' => $subject,
                    'provider' => $provider,
                    'model' => $model,
                    'processing_time' => $processingTime,
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to send email response', [
                    'to' => $fromEmail,
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the whole request if email sending fails
            }

            return $this->json([
                'success' => true,
                'message_id' => $message->getId(),
                'chat_id' => $chat->getId(),
                'response' => [
                    'text' => $responseText,
                    'metadata' => $aiResponse['metadata'] ?? [],
                ],
                'user_info' => [
                    'is_anonymous' => $userResult['is_anonymous'] ?? false,
                    'rate_limit_level' => $user->getRateLimitLevel(),
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Email webhook processing failed', [
                'from' => $fromEmail ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal error processing email',
                'details' => 'dev' === $_ENV['APP_ENV'] ? $e->getMessage() : null,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * WhatsApp Webhook Verification (GET).
     *
     * GET /api/v1/webhooks/whatsapp
     *
     * Meta requires webhook verification with challenge
     */
    #[Route('/whatsapp', name: 'whatsapp_verify', methods: ['GET'])]
    public function whatsappVerify(Request $request): Response
    {
        $mode = $request->query->get('hub_mode');
        $token = $request->query->get('hub_verify_token');
        $challenge = $request->query->get('hub_challenge');

        if ('subscribe' === $mode && $token === $this->whatsappWebhookVerifyToken) {
            $this->logger->info('WhatsApp webhook verified');

            return new Response($challenge, Response::HTTP_OK, [
                'Content-Type' => 'text/plain',
            ]);
        }

        $this->logger->warning('WhatsApp webhook verification failed', [
            'mode' => $mode,
            'token_match' => $token === $this->whatsappWebhookVerifyToken,
        ]);

        return new Response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    /**
     * WhatsApp Webhook (POST).
     *
     * POST /api/v1/webhooks/whatsapp
     *
     * Receives messages from Meta WhatsApp Business API.
     * No authentication required - users are automatically found/created based on phone number.
     * Anonymous users get ANONYMOUS rate limits until they verify their phone.
     * https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/payload-examples
     */
    #[Route('/whatsapp', name: 'whatsapp', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/webhooks/whatsapp',
        summary: 'WhatsApp webhook endpoint',
        description: 'Handles incoming WhatsApp messages. No authentication required - anonymous users allowed.',
        tags: ['Webhooks']
    )]
    #[OA\Response(
        response: 200,
        description: 'WhatsApp message processed successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'processed', type: 'integer', example: 1),
                new OA\Property(
                    property: 'responses',
                    type: 'array',
                    items: new OA\Items(type: 'object')
                ),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid webhook payload')]
    #[OA\Response(response: 429, description: 'Rate limit exceeded')]
    public function whatsapp(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data || !isset($data['entry'])) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid WhatsApp webhook payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $responses = [];

            // Process all entries
            foreach ($data['entry'] as $entry) {
                foreach ($entry['changes'] as $change) {
                    if ('messages' !== $change['field']) {
                        continue;
                    }

                    $value = $change['value'];

                    // Skip status updates
                    if (empty($value['messages'])) {
                        continue;
                    }

                    foreach ($value['messages'] as $incomingMsg) {
                        // 1. Find or create user from phone number
                        $from = $incomingMsg['from'];
                        $userResult = $this->emailChatService->findOrCreateUserFromPhone($from);

                        if (isset($userResult['error'])) {
                            $responses[] = [
                                'success' => false,
                                'phone' => $from,
                                'error' => $userResult['error'],
                            ];
                            continue;
                        }

                        // 2. Wrap incoming message in a DTO to reduce arity/signature smells
                        $dto = IncomingMessageDto::fromPayload($incomingMsg, $value);

                        // 3. Delegate business logic to WhatsAppService
                        $responses[] = $this->whatsAppService->handleIncomingMessage(
                            $dto,
                            $userResult['user'],
                            $userResult['is_anonymous'] ?? false
                        );
                    }
                }
            }

            return $this->json([
                'success' => true,
                'processed' => count($responses),
                'responses' => $responses,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('WhatsApp webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal error processing WhatsApp message',
                'details' => 'dev' === $_ENV['APP_ENV'] ? $e->getMessage() : null,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generic Webhook for other channels.
     *
     * POST /api/v1/webhooks/generic
     */
    #[Route('/generic', name: 'generic', methods: ['POST'])]
    public function generic(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!$data || empty($data['message'])) {
            return $this->json([
                'success' => false,
                'error' => 'Missing required field: message',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check rate limit
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return $this->json([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'limit' => $rateLimitCheck['limit'],
                'used' => $rateLimitCheck['used'],
                'reset_at' => $rateLimitCheck['reset_at'] ?? null,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $message = new Message();
            $message->setUserId($user->getId());
            $message->setTrackingId(time());
            $message->setProviderIndex($data['channel'] ?? 'API');
            $message->setUnixTimestamp(time());
            $message->setDateTime(date('YmdHis'));
            $message->setMessageType('API');
            $message->setFile(0);
            $message->setTopic('CHAT');
            $message->setLanguage('en');
            $message->setText($data['message']);
            $message->setDirection('IN');
            $message->setStatus('processing');

            $this->em->persist($message);
            $this->em->flush(); // MUST flush before setMeta() to get message ID

            // Store custom metadata if provided
            if (!empty($data['metadata']) && is_array($data['metadata'])) {
                foreach ($data['metadata'] as $key => $value) {
                    if (is_string($value)) {
                        $message->setMeta($key, $value);
                    }
                }
            }
            $this->em->flush(); // Flush metadata

            $result = $this->messageProcessor->process($message);

            if (!$result['success']) {
                return $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Processing failed',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $response = $result['response'];
            $responseContent = $response['content'] ?? '';
            $responseMeta = $response['metadata'] ?? [];

            // Record usage with response content for token estimation
            $this->rateLimitService->recordUsage($user, 'MESSAGES', [
                'provider' => $responseMeta['provider'] ?? 'unknown',
                'model' => $responseMeta['model'] ?? 'unknown',
                'tokens' => 0,
                'source' => 'WEBHOOK',
                'response_text' => $responseContent,
                'input_text' => $message->getText(),
            ]);

            return $this->json([
                'success' => true,
                'message_id' => $message->getId(),
                'response' => [
                    'text' => $responseContent,
                    'metadata' => $responseMeta,
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Generic webhook failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function resolveAttachmentPathFromAiMetadata(array $metadata): ?string
    {
        $mediaType = strtolower((string) ($metadata['media_type'] ?? ''));
        if (!in_array($mediaType, ['image', 'video', 'audio'], true)) {
            return null;
        }

        $relativePath = $metadata['local_path'] ?? null;

        // Fallback: derive relative path from StreamController-compatible file.path.
        if (!is_string($relativePath) || '' === trim($relativePath)) {
            $filePath = $metadata['file']['path'] ?? null;
            if (is_string($filePath)) {
                $prefix = '/api/v1/files/uploads/';
                if (str_starts_with($filePath, $prefix)) {
                    $relativePath = substr($filePath, strlen($prefix));
                }
            }
        }

        if (!is_string($relativePath) || '' === trim($relativePath)) {
            return null;
        }

        $absolutePath = $this->getParameter('kernel.project_dir').'/var/uploads/'.ltrim($relativePath, '/');

        if (!is_file($absolutePath)) {
            $this->logger->warning('Email attachment not found on disk', [
                'media_type' => $mediaType,
                'relative_path' => $relativePath,
                'absolute_path' => $absolutePath,
            ]);

            return null;
        }

        return $absolutePath;
    }
}
