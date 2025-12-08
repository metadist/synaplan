<?php

namespace App\Controller;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\InternalEmailService;
use App\Service\Message\InferenceRouter;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessagePreProcessor;
use App\Service\PromptService;
use App\Service\WidgetService;
use App\Service\WordPressIntegrationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legacy API Compatibility Layer.
 *
 * Mappt alte API-Requests (POST /api.php?action=...) auf neue Symfony-Endpoints.
 * Wichtig für Widget-Kompatibilität während der Migration.
 */
#[Route('/api.php', name: 'legacy_api_')]
class LegacyApiController extends AbstractController
{
    public function __construct(
        private MessagePreProcessor $preProcessor,
        private MessageClassifier $classifier,
        private InferenceRouter $inferenceRouter,
        private MessageRepository $messageRepository,
        private UserRepository $userRepository,
        private WordPressIntegrationService $wordpressIntegrationService,
        private WidgetService $widgetService,
        private PromptService $promptService,
        private InternalEmailService $emailService,
        private FileStorageService $fileStorageService,
        private FileProcessor $fileProcessor,
        private VectorizationService $vectorizationService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Main Legacy Entry Point.
     *
     * Routed basierend auf 'action' Parameter
     */
    #[Route('', name: 'main', methods: ['GET', 'POST'])]
    public function main(Request $request): JsonResponse
    {
        $action = $request->query->get('action') ?? $request->request->get('action');

        $this->logger->info('Legacy API call', [
            'action' => $action,
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp(),
        ]);

        return match ($action) {
            'messageNew' => $this->messageNew($request),
            'messageGet' => $this->messageGet($request),
            'againOptions' => $this->againOptions($request),
            'getProfile' => $this->getProfile($request),
            'getWidgets' => $this->getWidgets($request),
            'createApiKey' => $this->createApiKey($request),
            'saveWidget' => $this->saveWidget($request),
            'promptUpdate' => $this->promptUpdate($request),
            'sendEmail' => $this->sendEmail($request),
            'verifyEmail' => $this->verifyEmail($request),
            'chatStream' => $this->chatStream($request),
            'ragUpload' => $this->ragUpload($request),
            'wpWizardComplete' => $this->wpWizardComplete($request),
            'wpStep1VerifyAndCreateUser' => $this->wpStep1($request),
            'wpStep2CreateApiKey' => $this->wpStep2($request),
            'wpStep3UploadFile' => $this->wpStep3($request),
            'wpStep4EnableFileSearch' => $this->wpStep4($request),
            'wpStep5SaveWidget' => $this->wpStep5($request),
            default => $this->error('Unknown action: '.$action, 404),
        };
    }

    /**
     * Legacy: messageNew
     * → Neues System: POST /api/messages/send.
     */
    private function messageNew(Request $request): JsonResponse
    {
        try {
            // Legacy Parameters
            $userId = $request->get('user_id') ?? $request->getSession()->get('USERPROFILE')['BID'] ?? 1;
            $messageText = $request->get('message') ?? $request->get('text') ?? '';
            $widgetId = $request->get('widget_id') ?? $request->getSession()->get('widget_id');

            if (empty($messageText)) {
                return $this->error('Message text cannot be empty', 400);
            }

            // Create Message Entity
            $message = new Message();
            $message->setUserId((int) $userId);
            $message->setText($messageText);
            $message->setDirect('IN');
            $message->setStatus('NEW');
            $message->setUnixTimestamp(time());
            $message->setDatetime(date('YmdHis'));
            $message->setMessType('WEB');
            $message->setTrackId(time());

            // Process
            $this->preProcessor->process($message);
            $this->messageRepository->save($message);

            $this->classifier->classify($message);
            $this->messageRepository->save($message);

            $aiResponse = $this->inferenceRouter->route($message);
            $this->messageRepository->save($aiResponse);

            // Legacy Response Format
            return new JsonResponse([
                'success' => true,
                'tracking_id' => 'msg_'.$message->getId(),
                'message_id' => $message->getId(),
                'response_id' => $aiResponse->getId(),
                'response' => $aiResponse->getText(),
                'status' => 'completed',
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Legacy messageNew failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Legacy: messageGet
     * Holt Message by ID or Tracking-ID.
     */
    private function messageGet(Request $request): JsonResponse
    {
        $messageId = $request->get('message_id') ?? $request->get('id');
        $trackingId = $request->get('tracking_id');

        try {
            if ($trackingId) {
                $message = $this->messageRepository->findOneBy(['trackId' => $trackingId]);
            } elseif ($messageId) {
                $message = $this->messageRepository->find($messageId);
            } else {
                return $this->error('Missing message_id or tracking_id', 400);
            }

            if (!$message) {
                return $this->error('Message not found', 404);
            }

            return new JsonResponse([
                'success' => true,
                'message' => [
                    'id' => $message->getId(),
                    'user_id' => $message->getUserId(),
                    'text' => $message->getText(),
                    'direction' => $message->getDirection(),
                    'status' => $message->getStatus(),
                    'topic' => $message->getTopic(),
                    'language' => $message->getLang(),
                    'timestamp' => $message->getUnixTimestamp(),
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Legacy messageGet failed', ['error' => $e->getMessage()]);

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Legacy: againOptions
     * Gibt alternative Antwort-Optionen zurück.
     */
    private function againOptions(Request $request): JsonResponse
    {
        $messageId = $request->get('in_id') ?? $request->get('message_id');

        try {
            $message = $this->messageRepository->find($messageId);

            if (!$message) {
                return $this->error('Message not found', 404);
            }

            // Generate alternative responses (simplified)
            return new JsonResponse([
                'success' => true,
                'options' => [
                    'regenerate' => true,
                    'edit' => true,
                    'delete' => true,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Legacy: getProfile
     * Gibt User-Profile zurück.
     */
    private function getProfile(Request $request): JsonResponse
    {
        $userId = $request->get('user_id') ?? $request->getSession()->get('USERPROFILE')['BID'] ?? null;

        if (!$userId) {
            return $this->error('User not authenticated', 401);
        }

        try {
            $user = $this->userRepository->find($userId);

            if (!$user) {
                return $this->error('User not found', 404);
            }

            return new JsonResponse([
                'success' => true,
                'profile' => [
                    'id' => $user->getId(),
                    'email' => $user->getMail(),
                    'level' => $user->getUserlevel(),
                    'created' => $user->getCreated(),
                    'details' => $user->getUserdetails(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Legacy: chatStream
     * SSE Streaming (simplified redirect).
     */
    private function chatStream(Request $request): Response
    {
        $messageId = $request->get('message_id');

        // Redirect to new streaming endpoint
        return $this->redirectToRoute('api_messages_stream', [
            'id' => $messageId,
        ]);
    }

    /**
     * Legacy: ragUpload
     * File upload for RAG.
     */
    private function ragUpload(Request $request): JsonResponse
    {
        $userId = $this->getUserIdFromRequest($request);

        if (!$userId) {
            return $this->error('User not authenticated', 401);
        }

        try {
            $file = $request->files->get('file');
            if (null === $file) {
                return $this->error('No file uploaded', 400);
            }

            // Use WordPressIntegrationService which handles file upload
            $result = $this->wordpressIntegrationService->step3UploadFile($userId, $file);

            return new JsonResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Legacy ragUpload failed', ['error' => $e->getMessage()]);

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Legacy: getWidgets
     * Returns list of widgets for a user.
     */
    private function getWidgets(Request $request): JsonResponse
    {
        $userId = $this->getUserIdFromRequest($request);

        if (!$userId) {
            return $this->error('User not authenticated', 401);
        }

        try {
            $widgets = $this->widgetService->getWidgetsByUserId($userId);

            $widgetData = [];
            foreach ($widgets as $widget) {
                $widgetData[] = [
                    'id' => $widget->getId(),
                    'widget_id' => $widget->getWidgetId(),
                    'name' => $widget->getName(),
                    'config' => $widget->getConfig(),
                    'active' => $widget->isActive(),
                    'created' => $widget->getCreatedAt()?->format('Y-m-d H:i:s'),
                ];
            }

            return new JsonResponse([
                'success' => true,
                'widgets' => $widgetData,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Legacy getWidgets failed', ['error' => $e->getMessage()]);

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Legacy: createApiKey
     * Creates an API key for the authenticated user.
     */
    private function createApiKey(Request $request): JsonResponse
    {
        $userId = $this->getUserIdFromRequest($request);

        if (!$userId) {
            return $this->error('User not authenticated', 401);
        }

        try {
            $result = $this->wordpressIntegrationService->step2CreateApiKey($userId);

            return new JsonResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Legacy createApiKey failed', ['error' => $e->getMessage()]);

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Legacy: saveWidget
     * Saves widget configuration.
     */
    private function saveWidget(Request $request): JsonResponse
    {
        $userId = $this->getUserIdFromRequest($request);

        if (!$userId) {
            return $this->error('User not authenticated', 401);
        }

        try {
            $payload = $this->collectLegacyPayload($request);
            $result = $this->wordpressIntegrationService->step5SaveWidget($userId, $payload);

            return new JsonResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Legacy saveWidget failed', ['error' => $e->getMessage()]);

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Legacy: promptUpdate
     * Updates a prompt configuration.
     */
    private function promptUpdate(Request $request): JsonResponse
    {
        $userId = $this->getUserIdFromRequest($request);

        if (!$userId) {
            return $this->error('User not authenticated', 401);
        }

        try {
            $payload = $this->collectLegacyPayload($request);
            $topic = $payload['topic'] ?? $payload['prompt'] ?? null;

            if (!$topic) {
                return $this->error('Missing topic/prompt parameter', 400);
            }

            // Get prompt with metadata
            $promptData = $this->promptService->getPromptWithMetadata($topic, $userId);

            if (!$promptData) {
                return $this->error('Prompt not found', 404);
            }

            $prompt = $promptData['prompt'];

            // Update metadata if provided
            $metadata = [];
            if (isset($payload['tool_files'])) {
                $metadata['tool_files'] = (bool) $payload['tool_files'];
            }
            if (isset($payload['tool_internet'])) {
                $metadata['tool_internet'] = (bool) $payload['tool_internet'];
            }
            if (isset($payload['aiModel'])) {
                $metadata['aiModel'] = (int) $payload['aiModel'];
            }

            if (!empty($metadata)) {
                $this->promptService->saveMetadataForPrompt($prompt, array_merge($promptData['metadata'], $metadata));
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Prompt updated successfully',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Legacy promptUpdate failed', ['error' => $e->getMessage()]);

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Legacy: sendEmail
     * Sends a confirmation/verification email.
     */
    private function sendEmail(Request $request): JsonResponse
    {
        try {
            $payload = $this->collectLegacyPayload($request);
            $email = $payload['email'] ?? null;
            $type = $payload['type'] ?? 'confirmation';
            $locale = $payload['locale'] ?? 'en';

            if (!$email) {
                return $this->error('Email is required', 400);
            }

            // Generate a verification token
            $token = bin2hex(random_bytes(32));

            // Store token in user record or separate table
            $user = $this->userRepository->findByEmail($email);
            if (!$user) {
                return $this->error('User not found', 404);
            }

            // Send email based on type
            if ('confirmation' === $type || 'verification' === $type) {
                $this->emailService->sendVerificationEmail($email, $token, $locale);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Email sent successfully',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Legacy sendEmail failed', ['error' => $e->getMessage()]);

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Legacy: verifyEmail
     * Verifies an email token.
     */
    private function verifyEmail(Request $request): JsonResponse
    {
        try {
            $payload = $this->collectLegacyPayload($request);
            $token = $payload['token'] ?? null;

            if (!$token) {
                return $this->error('Token is required', 400);
            }

            // TODO: Implement proper token verification
            // For now, this is a placeholder that would need to check against stored tokens
            // The actual implementation depends on how tokens are stored (in User entity, separate table, etc.)

            return new JsonResponse([
                'success' => true,
                'message' => 'Email verified successfully',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Legacy verifyEmail failed', ['error' => $e->getMessage()]);

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Extract user ID from request (Authorization header or request parameter).
     */
    private function getUserIdFromRequest(Request $request): ?int
    {
        // Check for Bearer token in Authorization header
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            // TODO: Validate token and extract user ID
            // For now, check if token matches an API key
            $apiKey = $this->wordpressIntegrationService->getUserByApiKey($token);
            if ($apiKey) {
                return $apiKey;
            }
        }

        // Check request parameter
        $userId = $request->get('user_id');
        if ($userId) {
            return (int) $userId;
        }

        // Check session
        $session = $request->getSession();
        $profile = $session->get('USERPROFILE');
        if ($profile && isset($profile['BID'])) {
            return (int) $profile['BID'];
        }

        return null;
    }

    private function wpStep1(Request $request): JsonResponse
    {
        return $this->wpResponse(function () use ($request) {
            $payload = $this->collectLegacyPayload($request);

            return $this->wordpressIntegrationService->step1VerifyAndCreateUser($payload);
        });
    }

    private function wpStep2(Request $request): JsonResponse
    {
        return $this->wpResponse(function () use ($request) {
            $payload = $this->collectLegacyPayload($request);
            $userId = (int) ($payload['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new \InvalidArgumentException('user_id is required');
            }

            return $this->wordpressIntegrationService->step2CreateApiKey($userId);
        });
    }

    private function wpStep3(Request $request): JsonResponse
    {
        return $this->wpResponse(function () use ($request) {
            $payload = $this->collectLegacyPayload($request);
            $userId = (int) ($payload['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new \InvalidArgumentException('user_id is required');
            }

            $file = $request->files->get('file');
            if (null === $file) {
                throw new \InvalidArgumentException('file upload is required');
            }

            return $this->wordpressIntegrationService->step3UploadFile($userId, $file);
        });
    }

    private function wpStep4(Request $request): JsonResponse
    {
        return $this->wpResponse(function () use ($request) {
            $payload = $this->collectLegacyPayload($request);
            $userId = (int) ($payload['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new \InvalidArgumentException('user_id is required');
            }

            return $this->wordpressIntegrationService->step4EnableFileSearch($userId);
        });
    }

    private function wpStep5(Request $request): JsonResponse
    {
        return $this->wpResponse(function () use ($request) {
            $payload = $this->collectLegacyPayload($request);
            $userId = (int) ($payload['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new \InvalidArgumentException('user_id is required');
            }

            return $this->wordpressIntegrationService->step5SaveWidget($userId, $payload);
        });
    }

    private function wpWizardComplete(Request $request): JsonResponse
    {
        return $this->wpResponse(function () use ($request) {
            $payload = $this->collectLegacyPayload($request);
            $files = array_values($request->files->all());

            return $this->wordpressIntegrationService->completeWizard($payload, $files);
        });
    }

    /**
     * @param callable(): array $callback
     */
    private function wpResponse(callable $callback): JsonResponse
    {
        try {
            $result = $callback();

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Legacy WordPress wizard error', [
                'error' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function collectLegacyPayload(Request $request): array
    {
        $payload = array_merge($request->query->all(), $request->request->all());
        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains((string) $contentType, 'application/json') && $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        return $payload;
    }

    /**
     * Error Response Helper.
     */
    private function error(string $message, int $code = 400): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => $message,
            'code' => $code,
        ], $code);
    }
}
