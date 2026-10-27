<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\File\CloudFileRequestException;
use App\Service\File\CloudFilesConfig;
use App\Service\File\CloudFileStatus;
use App\Service\File\CloudSourceEvents;
use App\Service\PlatformLink\Exception\PlatformInstanceNotActiveException;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformInstanceSecretException;
use App\Service\PlatformLink\InstanceCredential;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Searchable state for files that live in a cloud.
 */
final class CloudFileController extends AbstractController
{
    public function __construct(
        private readonly CloudFilesConfig $cloudFiles,
        private readonly CloudFileStatus $status,
        private readonly CloudSourceEvents $events,
        private readonly InstanceCredential $instances,
    ) {
    }

    #[Route('/api/v1/cloud/status', name: 'api_cloud_status', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/cloud/status',
        operationId: 'cloudFileStatus',
        summary: 'Bird state for the files in one folder view',
        tags: ['Cloud files'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['items'],
            properties: [
                new OA\Property(property: 'items', type: 'array', maxItems: 500, items: new OA\Items(
                    required: ['id', 'version'],
                    properties: [
                        new OA\Property(property: 'id', type: 'string', maxLength: 255),
                        new OA\Property(property: 'version', type: 'string', maxLength: 255),
                    ],
                    type: 'object',
                )),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'One row per requested id, in the same order'),
            new OA\Response(response: 400, description: 'Not between 1 and 500 items, or a field is too long'),
            new OA\Response(response: 404, description: 'Cloud file state is turned off'),
        ],
    )]
    public function status(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $absent = $this->absent();
        if ($absent instanceof JsonResponse) {
            return $absent;
        }
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        try {
            $answer = $this->status->status($user, $request, $this->body($request));
        } catch (CloudFileRequestException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $cache = $answer['cache'];
        unset($answer['cache']);

        return $this->json($answer, Response::HTTP_OK, ['X-Synaplan-Cache' => $cache]);
    }

    #[Route('/api/v1/cloud/files/{id}', name: 'api_cloud_file_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/cloud/files/{id}',
        operationId: 'cloudFileDetail',
        summary: 'Sidebar detail for one searchable file',
        tags: ['Cloud files'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'State, knowledge folder and whether it is kept up to date'),
            new OA\Response(response: 404, description: 'Unknown file, or cloud file state is turned off'),
        ],
    )]
    public function detail(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $absent = $this->absent();
        if ($absent instanceof JsonResponse) {
            return $absent;
        }
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $row = $this->status->detail($user, $id, $request);
        if (null === $row) {
            return $this->json(['error' => 'File not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($row);
    }

    #[Route('/api/v1/platform-links/sources/events', name: 'api_cloud_source_events', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/platform-links/sources/events',
        operationId: 'cloudSourceEvents',
        summary: 'A cloud reports that files changed, moved, were deleted or came back',
        tags: ['Cloud files'],
        parameters: [
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'How many events applied, and which copies should be uploaded again'),
            new OA\Response(response: 400, description: 'The event list is empty, too long, or malformed'),
            new OA\Response(response: 401, description: 'Invalid instance credentials'),
            new OA\Response(response: 404, description: 'Cloud file state is turned off'),
        ],
    )]
    public function events(Request $request): JsonResponse
    {
        $absent = $this->absent();
        if ($absent instanceof JsonResponse) {
            return $absent;
        }
        try {
            $instance = $this->instances->require(
                (string) $request->headers->get('X-Instance-Id', ''),
                (string) $request->headers->get('X-Instance-Secret', ''),
            );
            $answer = $this->events->apply($instance, $this->body($request), $request->getClientIp() ?? '');
        } catch (CloudFileRequestException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        } catch (PlatformInstanceSecretException|PlatformInstanceNotActiveException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($answer);
    }

    private function absent(): ?JsonResponse
    {
        if ($this->cloudFiles->isEnabled()) {
            return null;
        }

        return $this->json([
            'error' => 'feature_not_configured',
            'module' => 'cloud_files',
            'docs' => 'modules/cloud-files',
        ], Response::HTTP_NOT_FOUND);
    }

    /**
     * @return array<mixed>
     */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
