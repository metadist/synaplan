<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Dropbox\DropboxConnectionService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Operator-side maintenance for the Dropbox connector.
 *
 * The per-user lifecycle (connect, test, delete own connection) lives in
 * {@see DropboxConnectionController} and {@see ConnectionController}; this
 * controller is only for actions that affect every user's grant at once.
 */
#[Route('/api/v1/admin/connections/dropbox')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin Connections')]
final class AdminDropboxConnectionController extends AbstractController
{
    public function __construct(
        private readonly DropboxConnectionService $dropbox,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/reset', name: 'admin_connections_dropbox_reset', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/connections/dropbox/reset',
        summary: 'Remove every Dropbox connection so users can reconnect freshly (admin only)',
        description: 'Deletes all Dropbox connections on this installation, including the stored OAuth tokens. Use after changing the Dropbox app registration (app key/secret or permissions), when every existing grant is stale. Users simply hit "Connect Dropbox" again; the consent recorded at dropbox.com is not revoked by this call.',
        security: [['Bearer' => []]],
        tags: ['Admin Connections']
    )]
    #[OA\Response(
        response: 200,
        description: 'All Dropbox connections were removed',
        content: new OA\JsonContent(
            required: ['success', 'removed'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'removed', type: 'integer', example: 3, description: 'Number of connections that were deleted'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function reset(): JsonResponse
    {
        $removed = $this->dropbox->resetAll();

        $this->logger->info('Admin reset all Dropbox connections', ['removed' => $removed]);

        return $this->json(['success' => true, 'removed' => $removed]);
    }
}
