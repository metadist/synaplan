<?php

namespace App\Controller;

use App\Dto\WhatsApp\IncomingMessageDto;
use App\Entity\Message;
use App\Entity\User;
use App\Service\EmailChatService;
use App\Service\InternalEmailService;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
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
        private RateLimitService $rateLimitService,
        private WhatsAppService $whatsAppService,
        private EmailChatService $emailChatService,
        private InternalEmailService $internalEmailService,
        private LoggerInterface $logger,
        private string $whatsappWebhookVerifyToken,
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

        $fromEmail = $data['from'];
        $toEmail = $data['to'];
        $subject = $data['subject'] ?? '(no subject)';
        $body = $data['body'];
        $messageId = $data['message_id'] ?? null;
        $inReplyTo = $data['in_reply_to'] ?? null;

        // Parse keyword from to-address (smart+keyword@synaplan.net)
        $keyword = $this->emailChatService->parseEmailKeyword($toEmail);

        $this->logger->info('Email webhook received', [
            'from' => $fromEmail,
            'to' => $toEmail,
            'keyword' => $keyword,
            'subject' => $subject,
            'body_length' => strlen($body),
        ]);

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

            if ($keyword) {
                $message->setMeta('email_keyword', $keyword);
            }
            if (!empty($subject)) {
                $message->setMeta('email_subject', $subject);
            }
            if ($messageId) {
                $message->setMeta('external_id', $messageId);
                // Note: Email threading is handled via Chat titles (Email: keyword or Email Conversation)
            }
            if (!empty($data['attachments'])) {
                $message->setMeta('has_attachments', 'true');
            }
            $this->em->flush(); // Flush metadata

            // Record usage (unified across all sources)
            $this->rateLimitService->recordUsage($user, 'MESSAGES');

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

            // Extract provider and model from metadata
            $provider = $metadata['provider'] ?? null;
            $model = $metadata['model'] ?? null;

            // Send email response back to user
            try {
                $this->internalEmailService->sendAiResponseEmail(
                    $fromEmail,
                    $subject,
                    $responseText,
                    $messageId,
                    $provider,
                    $model,
                    $processingTime
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

            // Record usage
            $this->rateLimitService->recordUsage($user, 'MESSAGES');

            $result = $this->messageProcessor->process($message);

            if (!$result['success']) {
                return $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Processing failed',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $response = $result['response'];

            return $this->json([
                'success' => true,
                'message_id' => $message->getId(),
                'response' => [
                    'text' => $response['content'] ?? '',
                    'metadata' => $response['metadata'] ?? [],
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
}
