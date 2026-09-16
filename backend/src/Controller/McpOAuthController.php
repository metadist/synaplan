<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpOAuthConsentService;
use App\Service\Mcp\McpOAuthException;
use App\Service\OAuth\OAuthException;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * OAuth consent for outbound MCP servers (Notion, Higgsfield, any RFC-9728 host).
 *
 * `/callback` is public: the provider redirects the browser cross-site and the
 * SameSite=Strict auth cookie is not sent. The HMAC-signed state identifies
 * the owner. `/start` and `/disconnect` stay authenticated.
 */
#[Route('/api/v1/mcp-servers', name: 'api_mcp_servers_oauth_')]
#[OA\Tag(name: 'MCP Servers')]
final class McpOAuthController extends AbstractController
{
    public function __construct(
        private readonly McpServerConfigRepository $repository,
        private readonly McpOAuthConsentService $consent,
        private readonly LoggerInterface $logger,
        private readonly string $frontendUrl,
    ) {
    }

    #[Route('/{id}/oauth/start', name: 'start', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/mcp-servers/{id}/oauth/start',
        summary: 'Begin the MCP OAuth sign-in for a saved server',
        description: 'Discovers the remote authorization server, registers this Synaplan install if needed, and returns the consent URL the browser must be sent to.',
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Consent URL created',
                content: new OA\JsonContent(
                    required: ['success', 'authorize_url'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'authorize_url', type: 'string', example: 'https://mcp.notion.com/authorize?client_id=…'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'OAuth connectors disabled by administrator'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function start(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $server = $this->repository->findByIdAndUser($id, $user->getId());
        if (null === $server) {
            return $this->json(['success' => false, 'error' => 'Server not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $url = $this->consent->start($server, $user->getId());
        } catch (McpOAuthException $e) {
            $status = str_contains($e->getMessage(), 'disabled by an administrator')
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_BAD_REQUEST;

            return $this->json(['success' => false, 'error' => $e->getMessage()], $status);
        }

        return $this->json(['success' => true, 'authorize_url' => $url]);
    }

    #[Route('/oauth/callback', name: 'callback', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/mcp-servers/oauth/callback',
        summary: 'OAuth provider redirects the browser here after consent',
        description: 'Public by design: the request arrives cross-site, so no auth cookie is present. The signed state identifies the user. Always answers with a redirect back to Channels → MCP Servers.',
        tags: ['MCP Servers'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'error', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirect to /channels/mcp with connected={id} or oauth_error={reason}'),
        ]
    )]
    public function callback(Request $request): Response
    {
        $error = $request->query->get('error');
        if (is_string($error) && '' !== $error) {
            $this->logger->warning('MCP OAuth consent was refused', ['error' => $error]);

            return $this->resultRedirect(null, 'denied');
        }

        $code = $request->query->get('code');
        $state = $request->query->get('state');
        if (!is_string($code) || '' === $code || !is_string($state) || '' === $state) {
            return $this->resultRedirect(null, 'missing_code');
        }

        try {
            $server = $this->consent->complete($code, $state);
        } catch (McpOAuthException|OAuthException $e) {
            $this->logger->warning('MCP OAuth consent could not be completed', ['reason' => $e->getMessage()]);

            $reason = str_contains($e->getMessage(), 'disabled by an administrator') ? 'disabled' : 'exchange_failed';

            return $this->resultRedirect(null, $reason);
        }

        return $this->resultRedirect($server->getId());
    }

    #[Route('/{id}/oauth/disconnect', name: 'disconnect', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/mcp-servers/{id}/oauth/disconnect',
        summary: 'Clear the stored OAuth tokens for a server (keep the connection row)',
        security: [['Bearer' => []]],
        tags: ['MCP Servers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tokens cleared',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true)])
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function disconnect(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $server = $this->repository->findByIdAndUser($id, $user->getId());
        if (null === $server) {
            return $this->json(['success' => false, 'error' => 'Server not found'], Response::HTTP_NOT_FOUND);
        }

        $this->consent->disconnect($server);

        return $this->json(['success' => true]);
    }

    private function resultRedirect(?int $serverId, ?string $reason = null): Response
    {
        $query = [];
        if (null !== $serverId) {
            $query['connected'] = (string) $serverId;
        }
        if (null !== $reason) {
            $query['oauth_error'] = preg_replace('/[^a-z0-9_]/i', '', $reason) ?? 'unknown';
        }

        return $this->redirect(rtrim($this->frontendUrl, '/').'/channels/mcp?'.http_build_query($query));
    }
}
