<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\CloudWatch\CloudWatchException;
use App\Service\CloudWatch\CloudWatchIndex;
use App\Service\CloudWatch\CloudWatchService;
use App\Service\CloudWatch\WatchUpload;
use App\Service\File\CloudFilesConfig;
use App\Service\PlatformLink\Exception\PlatformInstanceNotActiveException;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformInstanceSecretException;
use App\Service\PlatformLink\InstanceCredential;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Watched folders and files. The person's key carries cloud:watch.
 * The index is the instance credential, the same way source events are.
 */
final class CloudWatchController extends AbstractController
{
    public function __construct(
        private readonly CloudFilesConfig $cloudFiles,
        private readonly CloudWatchService $watches,
        private readonly WatchUpload $uploads,
        private readonly CloudWatchIndex $index,
        private readonly InstanceCredential $instances,
    ) {
    }

    #[Route('/api/v1/cloud/watches', name: 'api_cloud_watches_list', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/cloud/watches', operationId: 'cloudWatches', summary: 'My watches on this cloud', tags: ['Cloud watches'], responses: [
        new OA\Response(response: 200, description: 'Watches that are not stopped, and how many are paused because the link is off'),
        new OA\Response(response: 404, description: 'Cloud file state is turned off'),
    ])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->gate($user);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->answer(fn (): array => $this->watches->list($user, $request));
    }

    #[Route('/api/v1/cloud/watches/options', name: 'api_cloud_watches_options', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/cloud/watches/options', operationId: 'cloudWatchOptions', summary: 'Tasks, languages and folders the watch dialog can offer', tags: ['Cloud watches'], responses: [
        new OA\Response(response: 200, description: 'Manual tasks and the languages a translation can use'),
    ])]
    public function options(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->gate($user);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->json($this->watches->options($user));
    }

    #[Route('/api/v1/cloud/watches', name: 'api_cloud_watches_create', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/cloud/watches', operationId: 'cloudWatchCreate', summary: 'Start watching a folder or a file', tags: ['Cloud watches'], responses: [
        new OA\Response(response: 200, description: 'The new watch'),
        new OA\Response(response: 409, description: 'This person or this cloud is already at the cap'),
    ])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->gate($user);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->answer(fn (): array => $this->watches->create($user, $request, $this->body($request)));
    }

    #[Route('/api/v1/cloud/watches/{id}', name: 'api_cloud_watches_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[OA\Patch(path: '/api/v1/cloud/watches/{id}', operationId: 'cloudWatchUpdate', summary: 'Pause or resume a watch', tags: ['Cloud watches'], responses: [
        new OA\Response(response: 200, description: 'The watch, and how many files are waiting'),
    ])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->gate($user);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->answer(fn (): array => $this->watches->update($user, $request, $id, $this->body($request)));
    }

    #[Route('/api/v1/cloud/watches/{id}', name: 'api_cloud_watches_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(path: '/api/v1/cloud/watches/{id}', operationId: 'cloudWatchStop', summary: 'Stop watching. The row stays for the run history', tags: ['Cloud watches'], responses: [
        new OA\Response(response: 200, description: 'The watch is stopped'),
    ])]
    public function stop(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->gate($user);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->answer(fn (): array => $this->watches->stop($user, $request, $id));
    }

    #[Route('/api/v1/cloud/watches/{id}/files', name: 'api_cloud_watches_files', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(path: '/api/v1/cloud/watches/{id}/files', operationId: 'cloudWatchFile', summary: 'Upload one file version for a watch', tags: ['Cloud watches'], responses: [
        new OA\Response(response: 200, description: 'Accepted, waiting, skipped, or already handled'),
    ])]
    public function files(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->gate($user);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->answer(function () use ($user, $request, $id): array {
            $identity = $this->watches->identity($user, $request);
            $upload = $request->files->get('file');

            return $this->uploads->receive(
                $this->watches->open($user, $request, $id),
                $user,
                $this->watches->platformFor($identity),
                $request->request->all(),
                $upload instanceof UploadedFile ? $upload : null,
                $this->watches->instanceWatchIds($identity),
                time(),
            );
        });
    }

    #[Route('/api/v1/cloud/watches/{id}/reconcile', name: 'api_cloud_watches_reconcile', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(path: '/api/v1/cloud/watches/{id}/reconcile', operationId: 'cloudWatchReconcile', summary: 'Which files the app should upload, and which it can forget', tags: ['Cloud watches'], responses: [
        new OA\Response(response: 200, description: 'process and forget'),
    ])]
    public function reconcile(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->gate($user);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->answer(function () use ($user, $request, $id): array {
            $this->watches->identity($user, $request);
            $body = $this->body($request);
            $rows = [];
            foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $item) {
                if (is_array($item) && is_string($item['id'] ?? null) && is_string($item['version'] ?? null)) {
                    $rows[] = ['id' => $item['id'], 'version' => $item['version']];
                }
            }
            if ([] === $rows || count($rows) > 500) {
                throw new CloudWatchException(400, 'Send between 1 and 500 files.');
            }

            return $this->uploads->reconcile($this->watches->open($user, $request, $id), $rows);
        });
    }

    #[Route('/api/v1/platform-links/watches', name: 'api_cloud_watch_index', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/platform-links/watches',
        operationId: 'cloudWatchIndex',
        summary: 'Watches on this cloud, one page at a time',
        tags: ['Cloud watches'],
        parameters: [
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 500)),
            new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'since', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page, or the changes since a cursor'),
            new OA\Response(response: 304, description: 'Nothing changed'),
            new OA\Response(response: 410, description: 'The cursor is older than 7 days'),
        ],
    )]
    public function index(Request $request): JsonResponse|Response
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
            $feed = $this->index->feed($instance, $request, time());
        } catch (CloudWatchException $e) {
            return $this->json(['error' => $e->error, 'message' => $e->getMessage()], $e->status);
        } catch (PlatformInstanceNotFoundException) {
            return $this->json(['error' => 'Instance not found.'], Response::HTTP_NOT_FOUND);
        } catch (PlatformInstanceSecretException|PlatformInstanceNotActiveException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }
        if (304 === $feed['status']) {
            return new Response('', Response::HTTP_NOT_MODIFIED, ['ETag' => $feed['etag']]);
        }

        return $this->json($feed['body'], $feed['status'], ['ETag' => $feed['etag']]);
    }

    /**
     * @param callable(): array<string, mixed> $call
     */
    private function answer(callable $call): JsonResponse
    {
        try {
            return $this->json($call());
        } catch (CloudWatchException $e) {
            return $this->json(['error' => $e->error, 'message' => $e->getMessage()], $e->status);
        }
    }

    private function gate(?User $user): ?JsonResponse
    {
        $absent = $this->absent();
        if ($absent instanceof JsonResponse) {
            return $absent;
        }
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
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

    /** @return array<mixed> */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
