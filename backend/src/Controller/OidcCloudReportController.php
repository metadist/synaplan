<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\OidcBearerAuthenticator;
use App\Service\PlatformLink\Exception\OidcCloudReportException;
use App\Service\PlatformLink\OidcCloudReport;
use App\Service\PlatformLink\PlatformLinksConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The configured cloud's app says what it takes. A registered cloud does
 * this on its client registration; this cloud has no credential, so the
 * report rides a person's exchanged token.
 */
#[Route('/api/v1/platform-links/oidc-cloud', name: 'platform_links_oidc_cloud_')]
#[OA\Tag(name: 'Platform Links')]
final class OidcCloudReportController extends AbstractController
{
    public function __construct(
        private readonly PlatformLinksConfig $platformLinksConfig,
        private readonly OidcCloudReport $reports,
    ) {
    }

    #[Route('', name: 'report', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/platform-links/oidc-cloud',
        operationId: 'reportOidcCloudApp',
        summary: 'The configured cloud app reports what it takes',
        description: 'Bearer token of a person of that cloud (azp is PLATFORM_LINKS_OIDC_CLOUD_CLIENT_ID). Names this core does not know are ignored. The answer is the list that was kept.',
        tags: ['Platform Links'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['app_capabilities'],
                properties: [
                    new OA\Property(
                        property: 'app_capabilities',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        example: ['deliveries', 'pickups', 'read_scope', 'source_events', 'watches'],
                    ),
                    new OA\Property(property: 'software_version', type: 'string', example: '1.2.0'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The report was kept',
                content: new OA\JsonContent(
                    required: ['success', 'app_capabilities', 'software_version'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'app_capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['deliveries', 'pickups']),
                        new OA\Property(property: 'software_version', type: 'string', example: '1.2.0'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'The token is not from the configured cloud'),
            new OA\Response(response: 404, description: 'Platform links are off, or no cloud is configured'),
            new OA\Response(response: 422, description: 'The body is not a report'),
        ],
    )]
    public function report(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());
        if (!$user instanceof User || null === $user->getId()) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        try {
            $decoded = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['success' => false, 'error' => 'Send the app capabilities as JSON.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!is_array($decoded)) {
            return $this->json(['success' => false, 'error' => 'Send the app capabilities as JSON.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $claims = $request->attributes->get(OidcBearerAuthenticator::CLAIMS_ATTRIBUTE);

        try {
            $reported = $this->reports->accept(is_array($claims) ? $claims : [], $decoded, (int) $user->getId());
        } catch (OidcCloudReportException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], $e->status);
        }

        return $this->json(['success' => true, ...$reported]);
    }

    private function guard(?int $userId): void
    {
        if (!$this->platformLinksConfig->isEnabled($userId)) {
            throw new NotFoundHttpException();
        }
    }
}
