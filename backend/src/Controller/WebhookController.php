<?php

namespace App\Controller;

use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Email\EmailChatService;
use App\Service\Email\Turn\EmailConversation;
use App\Service\Email\Turn\EmailReply;
use App\Service\Email\Turn\InboundEmailAnswered;
use App\Service\Email\Turn\InboundEmailIntake;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Service\WhatsApp\WhatsAppService;
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
        private LoggerInterface $logger,
        private string $whatsappWebhookVerifyToken,
        private ChatErrorPresenter $chatErrorPresenter,
        private InboundEmailIntake $emailIntake,
        private EmailConversation $emailConversation,
        private EmailReply $emailReply,
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
                    description: 'Optional. Each item\'s url is fetched by the server (http/https only; private addresses are refused, DNS is pinned) and stored as a File on the receiving user, linked to the inbound message. At most 10 attachments and 128 MB total are fetched; unknown types, oversize, quota misses and the rest of an over-budget list are skipped. The email is still accepted. Automatic vectorization is not started.',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'filename', type: 'string', example: 'invoice.pdf'),
                            new OA\Property(property: 'content_type', type: 'string', example: 'application/pdf'),
                            new OA\Property(property: 'size', type: 'integer', example: 1024),
                            new OA\Property(property: 'url', type: 'string', example: 'https://relay.example.com/files/invoice.pdf', description: 'Public http(s) URL the server fetches. Query strings are allowed; the bytes are stored, not the URL.'),
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
        try {
            $email = $this->emailIntake->accept($request);
        } catch (InboundEmailAnswered $answered) {
            return $this->json($answered->payload, $answered->httpStatus);
        }

        try {
            return $this->json($this->emailReply->answer($email, $this->emailConversation->store($email)));
        } catch (\Exception $e) {
            return $this->json($this->emailReply->failure($email, $e), Response::HTTP_INTERNAL_SERVER_ERROR);
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
                $errorView = $this->chatErrorPresenter->presentFromResult(
                    $result,
                    $message->getLanguage() ?: 'en',
                    false,
                );

                return $this->json([
                    'success' => false,
                    'error' => $errorView->userText,
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $response = $result['response'];
            $responseContent = $response['content'] ?? '';
            $responseMeta = $response['metadata'] ?? [];

            $this->rateLimitService->recordUsage($user, 'MESSAGES', [
                'provider' => $responseMeta['provider'] ?? 'unknown',
                'model' => $responseMeta['model'] ?? 'unknown',
                'usage' => $responseMeta['usage'] ?? [],
                'model_id' => $responseMeta['model_id'] ?? null,
                'source' => 'WEBHOOK',
                'response_text' => $responseContent,
                'input_text' => $message->getText(),
            ]);

            $webhookMediaType = $responseMeta['media_type'] ?? null;
            if ($webhookMediaType) {
                $mediaAction = match ($webhookMediaType) {
                    'image' => 'IMAGES',
                    'video' => 'VIDEOS',
                    'audio' => 'AUDIOS',
                    default => null,
                };
                if ($mediaAction) {
                    $this->rateLimitService->recordUsage($user, $mediaAction, [
                        'provider' => $responseMeta['provider'] ?? 'unknown',
                        'model' => $responseMeta['model'] ?? 'unknown',
                        'model_id' => $responseMeta['model_id'] ?? null,
                        'source' => 'WEBHOOK',
                        'media_usage' => $responseMeta['media_usage'] ?? [],
                    ]);
                }
            }

            return $this->json([
                'success' => true,
                'message_id' => $message->getId(),
                'response' => [
                    'text' => $responseContent,
                    // Multi-task routing (Sprint 5): explicit file list for API
                    // clients. Falls back to the single metadata['file'] for
                    // legacy single-file turns; empty array when there is none.
                    'files' => is_array($responseMeta['files'] ?? null)
                        ? $responseMeta['files']
                        : (isset($responseMeta['file']) ? [$responseMeta['file']] : []),
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
}
