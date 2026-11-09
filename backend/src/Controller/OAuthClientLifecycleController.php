<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PlatformInstance;
use App\Service\OAuth\Server\ClientAssertionVerifier;
use App\Service\PlatformLink\Exception\PlatformSsoException;
use App\Service\PlatformLink\PlatformLifecycleService;
use App\Service\PlatformLink\PlatformLinksConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A cloud with accounts of its own tells Synaplan that one of them was
 * deleted, disabled or enabled again; Synaplan ends, pauses or resumes that
 * person's link ({@see PlatformLifecycleService}). The cloud authenticates
 * as the client, with the same assertion as for its client configuration.
 */
final class OAuthClientLifecycleController extends AbstractController
{
    public function __construct(
        private readonly ClientAssertionVerifier $assertions,
        private readonly PlatformLifecycleService $lifecycle,
        private readonly PlatformLinksConfig $platformLinks,
        #[Autowire('%env(APP_URL)%')]
        private readonly string $appUrl,
    ) {
    }

    #[Route('/oauth/register/{clientId}/lifecycle', name: 'oauth_client_lifecycle', methods: ['POST'])]
    public function lifecycle(Request $request, string $clientId): JsonResponse
    {
        if (!$this->platformLinks->isEnabled(null)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $header = (string) $request->headers->get('Authorization', '');
        $issuer = rtrim($this->appUrl, '/');
        $instance = str_starts_with($header, 'Bearer ')
            ? $this->assertions->verify(trim(substr($header, 7)), [$issuer.'/oauth/register/'.$clientId.'/lifecycle'])
            : null;
        if (!$instance instanceof PlatformInstance || $instance->getInstanceId() !== $clientId) {
            return $this->json(['error' => 'invalid_token', 'error_description' => 'A client assertion of this client is required.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        try {
            $result = $this->lifecycle->apply(
                $instance,
                is_string($body['external_id'] ?? null) ? $body['external_id'] : '',
                is_string($body['event'] ?? null) ? $body['event'] : '',
                $request->getClientIp() ?? '',
            );
        } catch (PlatformSsoException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }

        return $this->json($result);
    }
}
