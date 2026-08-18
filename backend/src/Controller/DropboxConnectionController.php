<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Dropbox\DropboxConnectionService;
use App\Service\Dropbox\DropboxOAuthConfig;
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
 * Dropbox consent flow — same contract as {@see MicrosoftConnectionController}.
 *
 * `/callback` is intentionally the only unauthenticated route: production auth
 * cookies are SameSite=Strict and are not sent when Dropbox redirects the
 * browser back to us. The caller is identified by the HMAC-signed OAuth state
 * instead.
 */
#[Route('/api/v1/connections/dropbox', name: 'api_connections_dropbox_')]
#[OA\Tag(name: 'Connections')]
final class DropboxConnectionController extends AbstractController
{
    public function __construct(
        private readonly DropboxConnectionService $dropbox,
        private readonly LoggerInterface $logger,
        private readonly string $frontendUrl,
    ) {
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/connections/dropbox/status',
        summary: 'Whether this installation can offer Dropbox connections',
        description: 'Reports if an operator has configured the Dropbox app (BCONFIG group DROPBOX). The frontend hides the "Connect Dropbox" action when available is false.',
        security: [['Bearer' => []]],
        tags: ['Connections'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Availability of the Dropbox connector',
                content: new OA\JsonContent(
                    required: ['success', 'available'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'available', type: 'boolean', example: false, description: 'True when app key, app secret and the enabled flag are all set'),
                        new OA\Property(property: 'redirect_uri', type: 'string', example: 'https://web.synaplan.com/api/v1/connections/dropbox/callback', description: 'Redirect URI that must be registered in the Dropbox App Console'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function status(#[CurrentUser] ?User $user, DropboxOAuthConfig $config): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'available' => $this->dropbox->isAvailable(),
            'redirect_uri' => $config->redirectUri(),
        ]);
    }

    #[Route('/start', name: 'start', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/connections/dropbox/start',
        summary: 'Begin the Dropbox consent flow',
        description: 'Returns the Dropbox sign-in URL the browser must be sent to. The URL is single-use: it carries a signed state and a PKCE challenge that expire after 10 minutes.',
        security: [['Bearer' => []]],
        tags: ['Connections'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Consent URL created',
                content: new OA\JsonContent(
                    required: ['success', 'authorize_url'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'authorize_url', type: 'string', example: 'https://www.dropbox.com/oauth2/authorize?client_id=…'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 503, description: 'Dropbox is not configured on this installation'),
        ]
    )]
    public function start(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $url = $this->dropbox->authorizationUrl((int) $user->getId());
        } catch (OAuthException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(['success' => true, 'authorize_url' => $url]);
    }

    #[Route('/callback', name: 'callback', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/connections/dropbox/callback',
        summary: 'Dropbox redirects the browser here after consent',
        description: 'Public by design: the request arrives cross-site from Dropbox, so no auth cookie is present. The signed state identifies the user. Always answers with a redirect back to the Connections page carrying a result flag.',
        tags: ['Connections'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'error', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Set when the user declined or Dropbox refused'),
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirect to the Connections page with dropbox=connected or dropbox=error'),
        ]
    )]
    public function callback(Request $request): Response
    {
        $error = $request->query->get('error');
        if (is_string($error) && '' !== $error) {
            $description = $request->query->get('error_description');

            $this->logger->warning('Dropbox consent was refused', [
                'error' => $error,
                'description' => is_string($description) ? $description : '',
            ]);

            return $this->resultRedirect('error', $error);
        }

        $code = $request->query->get('code');
        $state = $request->query->get('state');
        if (!is_string($code) || '' === $code || !is_string($state) || '' === $state) {
            return $this->resultRedirect('error', 'missing_code');
        }

        try {
            $this->dropbox->completeConsent($code, $state);
        } catch (OAuthException $e) {
            $this->logger->warning('Dropbox consent could not be completed', ['reason' => $e->getMessage()]);

            return $this->resultRedirect('error', 'exchange_failed');
        }

        return $this->resultRedirect('connected');
    }

    /**
     * The reason is a stable machine code, never a raw provider message: the
     * frontend translates it into one of the four locales, and an upstream
     * string could carry account details into a URL.
     */
    private function resultRedirect(string $result, ?string $reason = null): Response
    {
        $query = ['dropbox' => $result];
        if (null !== $reason) {
            $query['reason'] = preg_replace('/[^a-z0-9_]/i', '', $reason) ?? 'unknown';
        }

        return $this->redirect(rtrim($this->frontendUrl, '/').'/channels/connections?'.http_build_query($query));
    }
}
