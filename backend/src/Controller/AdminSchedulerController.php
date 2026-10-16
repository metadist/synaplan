<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Scheduler\ScheduledJobs;
use App\Service\Scheduler\ScheduledJobStatusReader;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Background job status for admins: whether the scheduler's every-minute
 * lane is alive and when each lane last ran. Read-only.
 *
 * SECURITY: requires ROLE_ADMIN (class-level IsGranted).
 */
#[Route('/api/v1/admin/scheduler')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin Scheduler')]
final class AdminSchedulerController extends AbstractController
{
    public function __construct(
        private readonly ScheduledJobStatusReader $reader,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/status', name: 'admin_scheduler_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/scheduler/status',
        summary: 'Get the background job status',
        description: 'Whether the scheduler is running (an every-minute job finished within maxAgeSeconds), stale, or has never run, plus the last run of each lane. Reads the shared job status only (admin only).',
        security: [['Bearer' => []]],
        tags: ['Admin Scheduler']
    )]
    #[OA\Response(
        response: 200,
        description: 'Background job status',
        content: new OA\JsonContent(
            required: ['state', 'maxAgeSeconds', 'checkedAt', 'lastRunAt', 'lanes'],
            properties: [
                new OA\Property(
                    property: 'state',
                    type: 'string',
                    enum: [ScheduledJobStatusReader::STATE_RUNNING, ScheduledJobStatusReader::STATE_STALE, ScheduledJobStatusReader::STATE_NEVER],
                    description: 'running: an every-minute job finished within maxAgeSeconds; stale: the newest finish is older; never: no every-minute job has finished',
                    example: ScheduledJobStatusReader::STATE_RUNNING
                ),
                new OA\Property(property: 'maxAgeSeconds', type: 'integer', description: 'Age after which the scheduler counts as stale', example: 600),
                new OA\Property(property: 'checkedAt', type: 'integer', description: 'Unix time of this check', example: 1759310400),
                new OA\Property(property: 'lastRunAt', type: 'integer', nullable: true, description: 'Unix time of the newest every-minute job finish, null when none finished', example: 1759310380),
                new OA\Property(
                    property: 'lanes',
                    type: 'array',
                    description: 'One entry per lane, in run order',
                    items: new OA\Items(
                        required: ['lane', 'lastStartedAt', 'lastFinishedAt', 'failedJobs', 'unfinishedJobs'],
                        properties: [
                            new OA\Property(
                                property: 'lane',
                                type: 'string',
                                enum: [ScheduledJobs::LANE_TICK, ScheduledJobs::LANE_TASKS, ScheduledJobs::LANE_HOURLY, ScheduledJobs::LANE_DAILY, ScheduledJobs::LANE_HEALTH],
                                example: ScheduledJobs::LANE_DAILY
                            ),
                            new OA\Property(property: 'lastStartedAt', type: 'integer', nullable: true, description: 'Newest job start in this lane', example: 1759289400),
                            new OA\Property(property: 'lastFinishedAt', type: 'integer', nullable: true, description: 'Newest job finish in this lane', example: 1759289460),
                            new OA\Property(
                                property: 'failedJobs',
                                type: 'array',
                                description: 'Console commands whose last run ended with a non-zero exit code',
                                items: new OA\Items(type: 'string', example: 'app:updates:check')
                            ),
                            new OA\Property(
                                property: 'unfinishedJobs',
                                type: 'array',
                                description: 'Console commands that started after their last finish: still running, or stopped by their time limit',
                                items: new OA\Items(type: 'string', example: 'app:digest:run')
                            ),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(
        response: 503,
        description: 'The shared job status could not be read',
        content: new OA\JsonContent(
            required: ['error'],
            properties: [new OA\Property(property: 'error', type: 'string', example: 'Background job status is unavailable')]
        )
    )]
    public function status(): JsonResponse
    {
        try {
            $report = $this->reader->read(ScheduledJobStatusReader::DEFAULT_MAX_AGE_SECONDS);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Background job status could not be read', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Background job status is unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'state' => $report->state,
            'maxAgeSeconds' => ScheduledJobStatusReader::DEFAULT_MAX_AGE_SECONDS,
            'checkedAt' => time(),
            'lastRunAt' => $report->lastTickFinishedAt(),
            'lanes' => $report->lanes(),
        ]);
    }
}
