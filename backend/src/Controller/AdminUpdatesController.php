<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\AdminUpdateDismissRequest;
use App\DTO\AdminUpdateSettingsRequest;
use App\Service\Update\ReleaseManifest;
use App\Service\Update\UpdatePlatformGuide;
use App\Service\Update\UpdateStatusService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Release-notice endpoints for admins.
 *
 * Detection and display ONLY: nothing here downloads, installs or selects a
 * version. `GET /status` reads values the daily scheduler check stored in
 * BCONFIG, so it works offline; `POST /check` is the manual "check now" button
 * and is the only endpoint that may perform an outbound request.
 *
 * SECURITY: all endpoints require ROLE_ADMIN (class-level IsGranted).
 */
#[Route('/api/v1/admin/updates')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin Updates')]
final class AdminUpdatesController extends AbstractController
{
    public function __construct(
        private readonly UpdateStatusService $updateStatusService,
        private readonly UpdatePlatformGuide $platformGuide,
    ) {
    }

    /**
     * Stored update status (no outbound request).
     */
    #[Route('/status', name: 'admin_updates_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/updates/status',
        summary: 'Get the stored update status',
        description: 'Returns the result of the last daily update check. Reads stored values only — no outbound HTTP, so it also works without internet access (admin only).',
        security: [['Bearer' => []]],
        tags: ['Admin Updates']
    )]
    #[OA\Response(
        response: 200,
        description: 'Stored update status',
        content: new OA\JsonContent(
            required: [
                'currentVersion',
                'latestVersion',
                'updateAvailable',
                'notesUrl',
                'severity',
                'releasedAt',
                'lastCheckedAt',
                'lastError',
                'checkEnabled',
                'dismissedVersion',
                'platform',
                'guideUrl',
            ],
            properties: [
                new OA\Property(
                    property: 'currentVersion',
                    type: 'string',
                    description: "Running release (APP_VERSION); 'dev' for a source checkout, which cannot be compared",
                    example: '4.0.12'
                ),
                new OA\Property(
                    property: 'latestVersion',
                    type: 'string',
                    nullable: true,
                    description: 'Latest known stable release, or null when no check has succeeded yet',
                    example: '4.0.13'
                ),
                new OA\Property(
                    property: 'updateAvailable',
                    type: 'boolean',
                    description: 'True only when the latest known release is strictly newer than the running one',
                    example: true
                ),
                new OA\Property(
                    property: 'notesUrl',
                    type: 'string',
                    nullable: true,
                    description: 'Release notes of the latest known release',
                    example: 'https://github.com/metadist/synaplan/releases/tag/v4.0.13'
                ),
                new OA\Property(
                    property: 'severity',
                    type: 'string',
                    enum: [ReleaseManifest::SEVERITY_NORMAL, ReleaseManifest::SEVERITY_SECURITY],
                    description: 'Importance of the latest known release',
                    example: ReleaseManifest::SEVERITY_NORMAL
                ),
                new OA\Property(
                    property: 'releasedAt',
                    type: 'string',
                    nullable: true,
                    description: 'Publication timestamp of the latest known release (ISO-8601)',
                    example: '2026-08-10T09:00:00Z'
                ),
                new OA\Property(
                    property: 'lastCheckedAt',
                    type: 'string',
                    nullable: true,
                    description: 'When the check last ran (ISO-8601, UTC), or null when it never ran',
                    example: '2026-08-10T09:05:00+00:00'
                ),
                new OA\Property(
                    property: 'lastError',
                    type: 'string',
                    nullable: true,
                    description: 'Why the last check could not complete; null after a successful check',
                    example: null
                ),
                new OA\Property(
                    property: 'checkEnabled',
                    type: 'boolean',
                    description: 'Master switch: while false no outbound request is ever made',
                    example: true
                ),
                new OA\Property(
                    property: 'dismissedVersion',
                    type: 'string',
                    nullable: true,
                    description: 'Version the admin acknowledged, so the notice can stay hidden',
                    example: null
                ),
                new OA\Property(
                    property: 'platform',
                    type: 'string',
                    enum: [UpdatePlatformGuide::PLATFORM_SELFHOST, UpdatePlatformGuide::PLATFORM_ELESTIO],
                    description: 'Deployment hint (SYNAPLAN_PLATFORM), used to pick the update guide',
                    example: UpdatePlatformGuide::PLATFORM_SELFHOST
                ),
                new OA\Property(
                    property: 'guideUrl',
                    type: 'string',
                    description: 'Manual-update guide for this platform — the link the update button opens',
                    example: UpdatePlatformGuide::GUIDE_URL_SELFHOST
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function getStatus(): JsonResponse
    {
        return $this->json($this->payload());
    }

    /**
     * Run the check now and return the refreshed status.
     */
    #[Route('/check', name: 'admin_updates_check', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/updates/check',
        summary: 'Check for a newer release now',
        description: 'Fetches the release manifest, stores the outcome and returns the refreshed status. A network failure is reported through lastError, not as an HTTP error. Does nothing when the master switch is off (admin only).',
        security: [['Bearer' => []]],
        tags: ['Admin Updates']
    )]
    #[OA\Response(
        response: 200,
        description: 'Refreshed update status — same payload as GET /api/v1/admin/updates/status',
        content: new OA\JsonContent(
            required: [
                'currentVersion',
                'latestVersion',
                'updateAvailable',
                'notesUrl',
                'severity',
                'releasedAt',
                'lastCheckedAt',
                'lastError',
                'checkEnabled',
                'dismissedVersion',
                'platform',
                'guideUrl',
            ],
            properties: [
                new OA\Property(property: 'currentVersion', type: 'string', example: '4.0.12'),
                new OA\Property(property: 'latestVersion', type: 'string', nullable: true, example: '4.0.13'),
                new OA\Property(property: 'updateAvailable', type: 'boolean', example: true),
                new OA\Property(
                    property: 'notesUrl',
                    type: 'string',
                    nullable: true,
                    example: 'https://github.com/metadist/synaplan/releases/tag/v4.0.13'
                ),
                new OA\Property(
                    property: 'severity',
                    type: 'string',
                    enum: [ReleaseManifest::SEVERITY_NORMAL, ReleaseManifest::SEVERITY_SECURITY],
                    example: ReleaseManifest::SEVERITY_NORMAL
                ),
                new OA\Property(property: 'releasedAt', type: 'string', nullable: true, example: '2026-08-10T09:00:00Z'),
                new OA\Property(property: 'lastCheckedAt', type: 'string', nullable: true, example: '2026-08-10T09:05:00+00:00'),
                new OA\Property(property: 'lastError', type: 'string', nullable: true, example: null),
                new OA\Property(property: 'checkEnabled', type: 'boolean', example: true),
                new OA\Property(property: 'dismissedVersion', type: 'string', nullable: true, example: null),
                new OA\Property(
                    property: 'platform',
                    type: 'string',
                    enum: [UpdatePlatformGuide::PLATFORM_SELFHOST, UpdatePlatformGuide::PLATFORM_ELESTIO],
                    example: UpdatePlatformGuide::PLATFORM_SELFHOST
                ),
                new OA\Property(property: 'guideUrl', type: 'string', example: UpdatePlatformGuide::GUIDE_URL_SELFHOST),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function check(): JsonResponse
    {
        $status = $this->updateStatusService->refresh();

        return $this->json($this->payload($status));
    }

    /**
     * Acknowledge a version so the notice can stay hidden.
     */
    #[Route('/dismiss', name: 'admin_updates_dismiss', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/updates/dismiss',
        summary: 'Acknowledge an available release',
        description: 'Stores the acknowledged version so the update notice can stay hidden until a newer release appears (admin only).',
        security: [['Bearer' => []]],
        tags: ['Admin Updates']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['version'],
            properties: [
                new OA\Property(property: 'version', type: 'string', example: '4.0.13'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Version acknowledged',
        content: new OA\JsonContent(
            required: ['success', 'dismissedVersion'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'dismissedVersion', type: 'string', nullable: true, example: '4.0.13'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function dismiss(#[MapRequestPayload] AdminUpdateDismissRequest $dto): JsonResponse
    {
        $this->updateStatusService->dismiss($dto->version);

        return $this->json([
            'success' => true,
            'dismissedVersion' => $this->updateStatusService->getStatus()['dismissedVersion'],
        ]);
    }

    /**
     * Toggle the master switch.
     */
    #[Route('/settings', name: 'admin_updates_settings', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/admin/updates/settings',
        summary: 'Toggle the update check',
        description: 'Enables or disables the daily update check. While disabled, no outbound request is ever made (admin only).',
        security: [['Bearer' => []]],
        tags: ['Admin Updates']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['checkEnabled'],
            properties: [
                new OA\Property(property: 'checkEnabled', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Master switch updated',
        content: new OA\JsonContent(
            required: ['success', 'checkEnabled'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'checkEnabled', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 422, description: 'Validation error')]
    public function updateSettings(#[MapRequestPayload] AdminUpdateSettingsRequest $dto): JsonResponse
    {
        $this->updateStatusService->setCheckEnabled((bool) $dto->checkEnabled);

        return $this->json([
            'success' => true,
            'checkEnabled' => $this->updateStatusService->getStatus()['checkEnabled'],
        ]);
    }

    /**
     * The status payload: the stored comparison plus the platform-specific
     * documentation link the update button opens.
     *
     * @param array{currentVersion: string, latestVersion: string|null, updateAvailable: bool, notesUrl: string|null, severity: string, releasedAt: string|null, lastCheckedAt: string|null, lastError: string|null, dismissedVersion: string|null, checkEnabled: bool}|null $status
     *
     * @return array<string, bool|string|null>
     */
    private function payload(?array $status = null): array
    {
        return ($status ?? $this->updateStatusService->getStatus()) + [
            'platform' => $this->platformGuide->platform(),
            'guideUrl' => $this->platformGuide->guideUrl(),
        ];
    }
}
