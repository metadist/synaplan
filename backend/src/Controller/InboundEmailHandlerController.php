<?php

namespace App\Controller;

use App\Entity\InboundEmailHandler;
use App\Entity\User;
use App\Repository\InboundEmailHandlerRepository;
use App\Service\EncryptionService;
use App\Service\InboundEmailHandlerService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Inbound Email Handler Controller.
 *
 * CRUD API for managing email handlers (IMAP/POP3 configuration).
 * This is a TOOL that allows users to automatically sort incoming emails.
 */
#[Route('/api/v1/inbound-email-handlers', name: 'api_inbound_email_handlers_')]
#[OA\Tag(name: 'Inbound Email Handlers')]
class InboundEmailHandlerController extends AbstractController
{
    public function __construct(
        private InboundEmailHandlerRepository $handlerRepository,
        private InboundEmailHandlerService $handlerService,
        private EncryptionService $encryptionService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * List all handlers for current user.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/inbound-email-handlers',
        summary: 'List all email handlers for current user',
        security: [['Bearer' => []]],
        tags: ['Inbound Email Handlers']
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $handlers = $this->handlerRepository->findByUser($user->getId());

        $handlersData = array_map(function (InboundEmailHandler $handler) {
            return $this->serializeHandler($handler);
        }, $handlers);

        return $this->json([
            'success' => true,
            'handlers' => $handlersData,
        ]);
    }

    /**
     * Get single handler by ID.
     */
    #[Route('/{id}', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/inbound-email-handlers/{id}',
        summary: 'Get email handler by ID',
        security: [['Bearer' => []]],
        tags: ['Inbound Email Handlers']
    )]
    public function get(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $handler = $this->handlerRepository->findByIdAndUser($id, $user->getId());

        if (!$handler) {
            return $this->json([
                'success' => false,
                'error' => 'Handler not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'handler' => $this->serializeHandler($handler),
        ]);
    }

    /**
     * Create new handler.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/inbound-email-handlers',
        summary: 'Create new email handler',
        security: [['Bearer' => []]],
        tags: ['Inbound Email Handlers']
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (empty($data['name']) || empty($data['mailServer']) || empty($data['username']) || empty($data['password'])) {
            return $this->json([
                'success' => false,
                'error' => 'Missing required fields: name, mailServer, username, password',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate departments
        if (empty($data['departments']) || !is_array($data['departments'])) {
            return $this->json([
                'success' => false,
                'error' => 'At least one department is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $handler = new InboundEmailHandler();
        $handler->setUserId($user->getId());
        $handler->setName($data['name']);
        $handler->setMailServer($data['mailServer']);
        $handler->setPort($data['port'] ?? 993);
        $handler->setProtocol($data['protocol'] ?? 'IMAP');
        $handler->setSecurity($data['security'] ?? 'SSL/TLS');
        $handler->setUsername($data['username']);
        $handler->setDecryptedPassword($data['password'], $this->encryptionService);
        $handler->setCheckInterval($data['checkInterval'] ?? 10);
        $handler->setDeleteAfter($data['deleteAfter'] ?? false);
        $handler->setStatus('active'); // Start as active by default
        $handler->setDepartments($data['departments']);

        // SMTP credentials for forwarding (optional, encrypted)
        if (isset($data['smtpServer']) && isset($data['smtpUsername']) && isset($data['smtpPassword'])) {
            $handler->setSmtpCredentials(
                trim($data['smtpServer']),
                $data['smtpPort'] ?? 587,
                trim($data['smtpUsername']), // Trim to avoid encoding issues
                $data['smtpPassword'],
                $this->encryptionService,
                $data['smtpSecurity'] ?? 'STARTTLS'
            );
        }

        // Email filter configuration
        $emailFilterMode = $data['emailFilterMode'] ?? 'new';

        // PRO+ required for historical emails
        if ('historical' === $emailFilterMode && !in_array($user->getUserLevel(), ['PRO', 'TEAM', 'BUSINESS', 'ADMIN'])) {
            return $this->json([
                'success' => false,
                'error' => 'Historical email processing is only available for PRO users and above',
            ], Response::HTTP_FORBIDDEN);
        }

        $handler->setEmailFilter(
            $emailFilterMode,
            $data['emailFilterFromDate'] ?? null
        );

        $this->em->persist($handler);
        $this->em->flush();

        $this->logger->info('Email handler created', [
            'handler_id' => $handler->getId(),
            'user_id' => $user->getId(),
        ]);

        return $this->json([
            'success' => true,
            'handler' => $this->serializeHandler($handler),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update handler.
     */
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/inbound-email-handlers/{id}',
        summary: 'Update email handler',
        security: [['Bearer' => []]],
        tags: ['Inbound Email Handlers']
    )]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $handler = $this->handlerRepository->findByIdAndUser($id, $user->getId());

        if (!$handler) {
            return $this->json([
                'success' => false,
                'error' => 'Handler not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $handler->setName($data['name']);
        }
        if (isset($data['mailServer'])) {
            $handler->setMailServer($data['mailServer']);
        }
        if (isset($data['port'])) {
            $handler->setPort($data['port']);
        }
        if (isset($data['protocol'])) {
            $handler->setProtocol($data['protocol']);
        }
        if (isset($data['security'])) {
            $handler->setSecurity($data['security']);
        }
        if (isset($data['username'])) {
            $handler->setUsername($data['username']);
        }
        if (isset($data['password'])) {
            $handler->setDecryptedPassword($data['password'], $this->encryptionService);
        }
        if (isset($data['checkInterval'])) {
            $handler->setCheckInterval($data['checkInterval']);
        }
        if (isset($data['deleteAfter'])) {
            $handler->setDeleteAfter($data['deleteAfter']);
        }
        if (isset($data['status'])) {
            $handler->setStatus($data['status']);
        }
        if (isset($data['departments'])) {
            $handler->setDepartments($data['departments']);
        }

        // Update SMTP credentials for forwarding (optional, encrypted)
        if (isset($data['smtpServer']) && isset($data['smtpUsername']) && isset($data['smtpPassword'])) {
            $handler->setSmtpCredentials(
                trim($data['smtpServer']),
                $data['smtpPort'] ?? 587,
                trim($data['smtpUsername']), // Trim to avoid encoding issues
                $data['smtpPassword'],
                $this->encryptionService,
                $data['smtpSecurity'] ?? 'STARTTLS'
            );
        }

        // Update email filter configuration
        if (isset($data['emailFilterMode'])) {
            // PRO+ required for historical emails
            if ('historical' === $data['emailFilterMode'] && !in_array($user->getUserLevel(), ['PRO', 'TEAM', 'BUSINESS', 'ADMIN'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Historical email processing is only available for PRO users and above',
                ], Response::HTTP_FORBIDDEN);
            }

            $handler->setEmailFilter(
                $data['emailFilterMode'],
                $data['emailFilterFromDate'] ?? null
            );
        }

        $handler->touch();
        $this->em->flush();

        $this->logger->info('Email handler updated', [
            'handler_id' => $handler->getId(),
            'user_id' => $user->getId(),
        ]);

        return $this->json([
            'success' => true,
            'handler' => $this->serializeHandler($handler),
        ]);
    }

    /**
     * Delete handler.
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/inbound-email-handlers/{id}',
        summary: 'Delete email handler',
        security: [['Bearer' => []]],
        tags: ['Inbound Email Handlers']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        description: 'Handler ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Handler deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Handler deleted'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Handler not found')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $handler = $this->handlerRepository->findByIdAndUser($id, $user->getId());

        if (!$handler) {
            return $this->json([
                'success' => false,
                'error' => 'Handler not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($handler);
        $this->em->flush();

        $this->logger->info('Email handler deleted', [
            'handler_id' => $id,
            'user_id' => $user->getId(),
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Handler deleted successfully',
        ]);
    }

    /**
     * Test connection (both IMAP and SMTP).
     */
    #[Route('/{id}/test', name: 'test', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/inbound-email-handlers/{id}/test',
        summary: 'Test email handler connection',
        description: 'Tests both IMAP and SMTP connection and updates handler status',
        security: [['Bearer' => []]],
        tags: ['Inbound Email Handlers']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        description: 'Handler ID',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Connection test result',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Connection successful'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Handler not found')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 500, description: 'Connection test failed')]
    public function test(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $handler = $this->handlerRepository->findByIdAndUser($id, $user->getId());

        if (!$handler) {
            return $this->json([
                'success' => false,
                'error' => 'Handler not found',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->handlerService->testConnection($handler);

            // Update handler status based on test result
            if ($result['success']) {
                $handler->setStatus('active');
            } else {
                $handler->setStatus('error');
            }
            $handler->touch();
            $this->em->flush();

            return $this->json([
                'success' => $result['success'],
                'message' => $result['message'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Test connection failed', [
                'handler_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'message' => 'Test connection failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Serialize handler for API response (hide password).
     */
    private function serializeHandler(InboundEmailHandler $handler): array
    {
        $config = $handler->getConfig() ?? [];

        // Extract SMTP config (mask password)
        $smtpConfig = null;
        if (isset($config['smtp'])) {
            $smtpConfig = [
                'server' => $config['smtp']['server'] ?? '',
                'port' => $config['smtp']['port'] ?? 587,
                'username' => $config['smtp']['username'] ?? '',
                'password' => '••••••••', // Never expose password
                'security' => $config['smtp']['security'] ?? 'STARTTLS',
            ];
        }

        // Extract email filter config
        $emailFilter = null;
        if (isset($config['email_filter'])) {
            $emailFilter = [
                'mode' => $config['email_filter']['mode'] ?? 'new',
                'fromDate' => $config['email_filter']['from_date'] ?? null,
            ];
        }

        return [
            'id' => $handler->getId(),
            'name' => $handler->getName(),
            'mailServer' => $handler->getMailServer(),
            'port' => $handler->getPort(),
            'protocol' => $handler->getProtocol(),
            'security' => $handler->getSecurity(),
            'username' => $handler->getUsername(),
            'password' => '••••••••', // Never expose password
            'checkInterval' => $handler->getCheckInterval(),
            'deleteAfter' => $handler->isDeleteAfter(),
            'status' => $handler->getStatus(),
            'departments' => $handler->getDepartments(),
            'smtpConfig' => $smtpConfig,
            'emailFilter' => $emailFilter,
            'lastChecked' => $handler->getLastChecked(),
            'created' => $handler->getCreated(),
            'updated' => $handler->getUpdated(),
        ];
    }

    /**
     * Bulk update handler status (activate/deactivate multiple handlers).
     */
    #[Route('/bulk-update-status', name: 'bulk_update_status', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/inbound-email-handlers/bulk-update-status',
        summary: 'Bulk update handler status',
        description: 'Activate or deactivate multiple email handlers at once',
        security: [['Bearer' => []]],
        tags: ['Inbound Email Handlers']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['handlerIds', 'status'],
            properties: [
                new OA\Property(
                    property: 'handlerIds',
                    type: 'array',
                    items: new OA\Items(type: 'integer'),
                    example: [1, 2, 3]
                ),
                new OA\Property(
                    property: 'status',
                    type: 'string',
                    enum: ['active', 'inactive'],
                    example: 'active'
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Handlers updated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'updated', type: 'integer', example: 3),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid parameters')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $handlerIds = $data['handlerIds'] ?? [];
        $status = $data['status'] ?? null;

        if (empty($handlerIds) || !in_array($status, ['active', 'inactive'])) {
            return $this->json(['success' => false, 'error' => 'Invalid parameters'], Response::HTTP_BAD_REQUEST);
        }

        $updated = 0;
        foreach ($handlerIds as $handlerId) {
            $handler = $this->handlerRepository->findOneBy(['id' => $handlerId, 'userId' => $user->getId()]);
            if ($handler) {
                $handler->setStatus($status);
                $handler->touch();
                ++$updated;
            }
        }

        $this->em->flush();

        return $this->json(['success' => true, 'updated' => $updated]);
    }

    /**
     * Bulk delete handlers (delete multiple handlers at once).
     */
    #[Route('/bulk-delete', name: 'bulk_delete', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/inbound-email-handlers/bulk-delete',
        summary: 'Bulk delete handlers',
        description: 'Delete multiple email handlers at once',
        security: [['Bearer' => []]],
        tags: ['Inbound Email Handlers']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['handlerIds'],
            properties: [
                new OA\Property(
                    property: 'handlerIds',
                    type: 'array',
                    items: new OA\Items(type: 'integer'),
                    example: [1, 2, 3]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Handlers deleted successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'deleted', type: 'integer', example: 3),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid parameters')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function bulkDelete(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $handlerIds = $data['handlerIds'] ?? [];

        if (empty($handlerIds)) {
            return $this->json(['success' => false, 'error' => 'Invalid parameters'], Response::HTTP_BAD_REQUEST);
        }

        $deleted = 0;
        foreach ($handlerIds as $handlerId) {
            $handler = $this->handlerRepository->findOneBy(['id' => $handlerId, 'userId' => $user->getId()]);
            if ($handler) {
                $this->em->remove($handler);
                ++$deleted;
            }
        }

        $this->em->flush();

        return $this->json(['success' => true, 'deleted' => $deleted]);
    }
}
