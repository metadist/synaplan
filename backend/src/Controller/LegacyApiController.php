<?php

namespace App\Controller;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Message\InferenceRouter;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessagePreProcessor;
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
            'chatStream' => $this->chatStream($request),
            'ragUpload' => $this->ragUpload($request),
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
        // TODO: Implement file upload
        return new JsonResponse([
            'success' => false,
            'error' => 'Not implemented yet',
        ], 501);
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
