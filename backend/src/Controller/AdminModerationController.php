<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentReport;
use App\Entity\User;
use App\Repository\ContentReportRepository;
use App\Repository\UserRepository;
use App\Service\ContentModerationService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Operator-facing content moderation (Apple App Review Guideline 1.2):
 * review reports and suspend/ban abusive accounts.
 */
#[Route('/api/v1/admin/moderation')]
#[OA\Tag(name: 'Admin Moderation')]
#[IsGranted('ROLE_ADMIN')]
final class AdminModerationController extends AbstractController
{
    public function __construct(
        private readonly ContentModerationService $moderationService,
        private readonly ContentReportRepository $reportRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route('/reports', name: 'admin_moderation_reports', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/moderation/reports',
        summary: 'List content reports',
        tags: ['Admin Moderation'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'reviewed', 'actioned', 'dismissed'])),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Paginated content reports',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'reports', type: 'array', items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 42),
                    new OA\Property(property: 'contentType', type: 'string', example: 'message'),
                    new OA\Property(property: 'contentId', type: 'integer', example: 12345),
                    new OA\Property(property: 'reason', type: 'string', example: 'harassment'),
                    new OA\Property(property: 'details', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', example: 'open'),
                    new OA\Property(property: 'reporterId', type: 'integer', example: 7),
                    new OA\Property(property: 'reportedUserId', type: 'integer', nullable: true, example: 9),
                    new OA\Property(property: 'reportedUserEmail', type: 'string', nullable: true),
                    new OA\Property(property: 'reportedUserStatus', type: 'string', nullable: true, example: 'active'),
                    new OA\Property(property: 'created', type: 'string', example: '2026-07-16 12:00:00'),
                    new OA\Property(property: 'reviewedBy', type: 'integer', nullable: true),
                    new OA\Property(property: 'reviewedAt', type: 'string', nullable: true),
                ]
            )),
            new OA\Property(property: 'total', type: 'integer', example: 3),
        ])
    )]
    public function listReports(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        $status = is_string($status) && '' !== $status ? $status : null;
        $page = $request->query->getInt('page', 1);
        $perPage = $request->query->getInt('perPage', 25);

        $result = $this->moderationService->listReports($status, $page, $perPage);

        return $this->json([
            'reports' => array_map(fn (ContentReport $r) => $this->serializeReport($r), $result['reports']),
            'total' => $result['total'],
        ]);
    }

    #[Route('/reports/{id}', name: 'admin_moderation_report_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        path: '/api/v1/admin/moderation/reports/{id}',
        summary: 'Update a content report status',
        tags: ['Admin Moderation']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['status'],
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['open', 'reviewed', 'actioned', 'dismissed']),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Updated report',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'id', type: 'integer', example: 42),
            new OA\Property(property: 'contentType', type: 'string', example: 'message'),
            new OA\Property(property: 'contentId', type: 'integer', example: 12345),
            new OA\Property(property: 'reason', type: 'string', example: 'harassment'),
            new OA\Property(property: 'details', type: 'string', nullable: true),
            new OA\Property(property: 'status', type: 'string', example: 'reviewed'),
            new OA\Property(property: 'reporterId', type: 'integer', example: 7),
            new OA\Property(property: 'reportedUserId', type: 'integer', nullable: true, example: 9),
            new OA\Property(property: 'reportedUserEmail', type: 'string', nullable: true),
            new OA\Property(property: 'reportedUserStatus', type: 'string', nullable: true, example: 'active'),
            new OA\Property(property: 'created', type: 'string', example: '2026-07-16 12:00:00'),
            new OA\Property(property: 'reviewedBy', type: 'integer', nullable: true),
            new OA\Property(property: 'reviewedAt', type: 'string', nullable: true),
        ])
    )]
    #[OA\Response(response: 400, description: 'Invalid status')]
    #[OA\Response(response: 404, description: 'Report not found')]
    public function updateReport(int $id, Request $request, #[CurrentUser] ?User $admin): JsonResponse
    {
        $report = $this->reportRepository->find($id);
        if (null === $report) {
            return $this->json(['error' => 'Report not found'], Response::HTTP_NOT_FOUND);
        }

        $decoded = json_decode($request->getContent(), true);
        $body = is_array($decoded) ? $decoded : [];
        $status = isset($body['status']) && is_string($body['status']) ? $body['status'] : '';

        try {
            $this->moderationService->updateReportStatus($report, $status, (int) $admin?->getId());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->serializeReport($report));
    }

    #[Route('/users/{id}/status', name: 'admin_moderation_user_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(
        path: '/api/v1/admin/moderation/users/{id}/status',
        summary: 'Suspend, ban, or reactivate a user account',
        tags: ['Admin Moderation']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['status'],
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['active', 'suspended', 'banned']),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Updated account status',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'id', type: 'integer', example: 9),
            new OA\Property(property: 'email', type: 'string', example: 'user@example.com'),
            new OA\Property(property: 'accountStatus', type: 'string', enum: ['active', 'suspended', 'banned'], example: 'suspended'),
        ])
    )]
    #[OA\Response(response: 400, description: 'Invalid status')]
    #[OA\Response(response: 404, description: 'User not found')]
    public function updateUserStatus(int $id, Request $request, #[CurrentUser] ?User $admin): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (null === $user) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        if ($user->getId() === $admin?->getId()) {
            return $this->json(['error' => 'You cannot change your own account status'], Response::HTTP_BAD_REQUEST);
        }

        $decoded = json_decode($request->getContent(), true);
        $body = is_array($decoded) ? $decoded : [];
        $status = isset($body['status']) && is_string($body['status']) ? $body['status'] : '';

        try {
            $this->moderationService->setAccountStatus($user, $status);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getMail(),
            'accountStatus' => $user->getAccountStatus(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReport(ContentReport $r): array
    {
        $reportedUser = null !== $r->getReportedUserId() ? $this->userRepository->find($r->getReportedUserId()) : null;

        return [
            'id' => $r->getId(),
            'contentType' => $r->getContentType(),
            'contentId' => $r->getContentId(),
            'reason' => $r->getReason(),
            'details' => $r->getDetails(),
            'status' => $r->getStatus(),
            'reporterId' => $r->getReporterId(),
            'reportedUserId' => $r->getReportedUserId(),
            'reportedUserEmail' => $reportedUser?->getMail(),
            'reportedUserStatus' => $reportedUser?->getAccountStatus(),
            'created' => $r->getCreated(),
            'reviewedBy' => $r->getReviewedBy(),
            'reviewedAt' => $r->getReviewedAt(),
        ];
    }
}
