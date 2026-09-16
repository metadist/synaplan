<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeRefusedException;
use App\Service\Compute\ComputeWorkspaceService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

#[Route('/api/v1/compute/workspace', name: 'api_compute_workspace_')]
#[OA\Tag(name: 'Compute')]
final class ComputeWorkspaceController extends AbstractController
{
    /** A media type token as the sidecar should send it; anything else is served as bytes. */
    private const MEDIA_TYPE = '#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}$#i';
    private const UNAVAILABLE_COPY = 'File work is not reachable right now. Nothing was changed.';

    public function __construct(
        private ComputeConfig $config,
        private ComputeWorkspaceService $workspaces,
    ) {
    }

    #[Route('', name: 'show', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/compute/workspace',
        summary: 'The current user\'s file-work folder (usage and quota)',
        tags: ['Compute'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Folder usage, or exists=false when none has been created yet',
                content: new OA\JsonContent(
                    required: ['exists', 'quotaMb', 'usedMb', 'fileCount'],
                    properties: [
                        new OA\Property(property: 'exists', type: 'boolean', example: false),
                        new OA\Property(property: 'quotaMb', type: 'integer', example: 256),
                        new OA\Property(property: 'usedMb', type: 'integer', example: 0),
                        new OA\Property(property: 'fileCount', type: 'integer', example: 0),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'File-work folders are off'),
        ]
    )]
    public function show(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $row = $this->workspaces->forUser($user);
        if (null === $row) {
            return $this->json([
                'exists' => false,
                'quotaMb' => 0,
                'usedMb' => 0,
                'fileCount' => 0,
            ]);
        }
        try {
            $usage = $this->workspaces->refreshUsage($row);
        } catch (ComputeRefusedException|HttpClientException) {
            // Usage is advisory: fall back to the last stored numbers.
            return $this->json([
                'exists' => true,
                'quotaMb' => $row->getQuotaMb(),
                'usedMb' => $row->getUsedMb(),
                'fileCount' => 0,
            ]);
        }

        return $this->json([
            'exists' => true,
            'quotaMb' => $usage->quotaMb,
            'usedMb' => $usage->usedMb,
            'fileCount' => $usage->fileCount,
        ]);
    }

    #[Route('/files', name: 'files', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/compute/workspace/files',
        summary: 'List files in the current user\'s file-work folder',
        tags: ['Compute'],
        parameters: [new OA\Parameter(name: 'path', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Files in the folder (never ingested)',
                content: new OA\JsonContent(
                    required: ['files'],
                    properties: [
                        new OA\Property(
                            property: 'files',
                            type: 'array',
                            items: new OA\Items(
                                required: ['path', 'size', 'mime', 'modifiedAt'],
                                properties: [
                                    new OA\Property(property: 'path', type: 'string'),
                                    new OA\Property(property: 'size', type: 'integer'),
                                    new OA\Property(property: 'mime', type: 'string'),
                                    new OA\Property(property: 'modifiedAt', type: 'string'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'File-work folders are off'),
            new OA\Response(response: 503, description: 'The compute sidecar did not answer'),
        ]
    )]
    public function files(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $path = (string) $request->query->get('path', '');
        try {
            $files = $this->workspaces->listFiles($user, $path);
        } catch (ComputeRefusedException $e) {
            return $this->refused($e);
        } catch (HttpClientException) {
            return $this->unavailable();
        }

        return $this->json([
            'files' => array_map(
                static fn ($file): array => [
                    'path' => $file->path,
                    'size' => $file->size,
                    'mime' => $file->mime,
                    'modifiedAt' => $file->modifiedAt,
                ],
                $files,
            ),
        ]);
    }

    #[Route('/files/{path}', name: 'download', methods: ['GET'], requirements: ['path' => '.+'])]
    #[OA\Get(
        path: '/api/v1/compute/workspace/files/{path}',
        summary: 'Download one file from the current user\'s file-work folder',
        tags: ['Compute'],
        parameters: [new OA\Parameter(name: 'path', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'File bytes'),
            new OA\Response(response: 404, description: 'File or folder not found'),
            new OA\Response(response: 503, description: 'The compute sidecar did not answer'),
        ]
    )]
    public function download(string $path, #[CurrentUser] ?User $user): Response
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        try {
            $file = $this->workspaces->downloadFile($user, $path);
        } catch (ComputeRefusedException $e) {
            return $this->refused($e);
        } catch (HttpClientException) {
            return $this->unavailable();
        }

        return new Response($file['contents'], Response::HTTP_OK, [
            'Content-Type' => $this->safeMediaType($file['mime']),
            'Content-Disposition' => 'attachment; filename="'.$this->safeDownloadName($file['name']).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/compute/workspace',
        summary: 'Delete the current user\'s file-work folder',
        tags: ['Compute'],
        responses: [
            new OA\Response(response: 204, description: 'Folder removed, or there was none'),
            new OA\Response(response: 404, description: 'File-work folders are off'),
            new OA\Response(response: 503, description: 'The compute sidecar did not answer'),
        ]
    )]
    public function delete(#[CurrentUser] ?User $user): Response
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        try {
            $this->workspaces->delete($user);
        } catch (ComputeRefusedException $e) {
            return $this->refused($e);
        } catch (HttpClientException) {
            return $this->unavailable();
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->config->workspacesEnabled($user->getId())) {
            return $this->json([
                'error' => 'feature_not_configured',
                'module' => 'compute',
            ], Response::HTTP_NOT_FOUND);
        }

        return null;
    }

    private function refused(ComputeRefusedException $e): JsonResponse
    {
        $status = 'workspace_not_found' === $e->errorCode() || 'bad_file_name' === $e->errorCode()
            ? Response::HTTP_NOT_FOUND
            : Response::HTTP_BAD_REQUEST;

        return $this->json([
            'error' => $e->errorCode(),
            'message' => $e->getMessage(),
        ], $status);
    }

    private function unavailable(): JsonResponse
    {
        return $this->json([
            'error' => 'compute_unavailable',
            'message' => self::UNAVAILABLE_COPY,
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function safeDownloadName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? 'file';

        return '' !== $clean ? $clean : 'file';
    }

    /**
     * The sidecar's Content-Type is trusted only as far as it is a plain media
     * type. Parameters (charset, boundary) and anything malformed are dropped so
     * the header can never carry more than `type/subtype`.
     */
    private function safeMediaType(string $mime): string
    {
        $type = trim(explode(';', $mime, 2)[0]);

        return 1 === preg_match(self::MEDIA_TYPE, $type) ? strtolower($type) : 'application/octet-stream';
    }
}
