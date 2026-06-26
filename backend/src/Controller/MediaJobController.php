<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Poll endpoint for background media jobs (Release 4.0 async video).
 *
 * The chat bubble shows a running placeholder immediately after detach; the
 * frontend polls here every ~25 s so the user sees elapsed time, last-checked
 * age, progress and terminal errors without holding an SSE connection open.
 */
#[Route('/api/v1/media-jobs', name: 'api_media_jobs_')]
#[OA\Tag(name: 'Media jobs')]
final class MediaJobController extends AbstractController
{
    public function __construct(
        private readonly MediaJobService $mediaJobService,
        private readonly MediaJobMessageSync $messageSync,
        private readonly MediaErrorMessageBuilder $errorBuilder,
    ) {
    }

    #[Route('/{jobKey}', name: 'status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/media-jobs/{jobKey}',
        summary: 'Poll the status of a background media job',
        tags: ['Media jobs'],
        parameters: [
            new OA\Parameter(name: 'jobKey', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Job status snapshot'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Job not found'),
        ]
    )]
    public function status(string $jobKey, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $job = $this->mediaJobService->findForUser($jobKey, $user->getId());
        if (null === $job) {
            return $this->json(['error' => 'Job not found'], Response::HTTP_NOT_FOUND);
        }

        // Safety net: if the worker/reaper missed the deadline, the user's poll
        // must still drive the job to a terminal state they can see.
        if ($this->mediaJobService->enforceDeadline(
            $job,
            $this->errorBuilder->buildTimeoutMessage(
                $job->getType(),
                $this->mediaJobService->langFromJob($job),
            ),
        )) {
            $this->messageSync->syncTerminalState($job);
        }

        return $this->json([
            'success' => true,
            'job' => $this->mediaJobService->toStatusArray($job),
        ]);
    }
}
