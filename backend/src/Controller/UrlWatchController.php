<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\UrlWatch\UrlWatchFetchFailedException;
use App\Service\UrlWatch\UrlWatchNotFoundException;
use App\Service\UrlWatch\UrlWatchService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/url-watches', name: 'api_url_watches_')]
#[OA\Tag(name: 'URL Watches')]
final class UrlWatchController extends AbstractController
{
    public function __construct(
        private UrlWatchService $service,
        private MultitaskRoutingConfig $routingConfig,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/url-watches',
        summary: 'List watched pages for the current user',
        tags: ['URL Watches'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Watched pages',
                content: new OA\JsonContent(
                    required: ['success', 'watches'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'watches', type: 'array', items: new OA\Items(
                            type: 'object',
                            required: ['id', 'url', 'title', 'preview', 'fetchedAt', 'created', 'updated'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'url', type: 'string', example: 'https://example.com/news'),
                                new OA\Property(property: 'title', type: 'string'),
                                new OA\Property(property: 'preview', type: 'string'),
                                new OA\Property(property: 'fetchedAt', type: 'string', nullable: true),
                                new OA\Property(property: 'created', type: 'string', nullable: true),
                                new OA\Property(property: 'updated', type: 'string', nullable: true),
                                new OA\Property(property: 'lastDiffText', type: 'string', nullable: true),
                                new OA\Property(property: 'lastError', type: 'string', nullable: true),
                                new OA\Property(property: 'lastFailedAt', type: 'string', nullable: true),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $watches = array_map(
            fn ($watch) => $this->service->toListItem($watch),
            $this->service->list((int) $user->getId()),
        );

        return $this->json(['success' => true, 'watches' => $watches]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/url-watches',
        summary: 'Watch a page (register the URL; first snapshot comes from Check now or a compare fetch)',
        tags: ['URL Watches'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['url'],
                properties: [
                    new OA\Property(property: 'url', type: 'string', example: 'https://example.com/news'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Existing or newly registered watch',
                content: new OA\JsonContent(
                    required: ['success', 'created', 'watch'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'created', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'watch',
                            type: 'object',
                            required: ['id', 'url', 'title', 'preview', 'fetchedAt', 'created', 'updated', 'body'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'url', type: 'string'),
                                new OA\Property(property: 'title', type: 'string'),
                                new OA\Property(property: 'preview', type: 'string'),
                                new OA\Property(property: 'fetchedAt', type: 'string', nullable: true),
                                new OA\Property(property: 'created', type: 'string', nullable: true),
                                new OA\Property(property: 'updated', type: 'string', nullable: true),
                                new OA\Property(property: 'body', type: 'string'),
                                new OA\Property(property: 'lastDiffText', type: 'string', nullable: true),
                                new OA\Property(property: 'lastError', type: 'string', nullable: true),
                                new OA\Property(property: 'lastFailedAt', type: 'string', nullable: true),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid or blocked URL'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $payload = $request->toArray();
        $url = trim((string) ($payload['url'] ?? ''));
        try {
            $result = $this->service->register((int) $user->getId(), $url);
        } catch (\InvalidArgumentException $e) {
            if ('blocked_url' === $e->getMessage()) {
                return $this->json(
                    ['error' => 'URL points to a private/blocked address'],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            return $this->json(['error' => 'invalid_url'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'success' => true,
            'created' => $result['created'],
            'watch' => $this->service->toDetail($result['watch']),
        ]);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/url-watches/{id}',
        summary: 'Get the saved copy of a watched page',
        tags: ['URL Watches'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Watch detail',
                content: new OA\JsonContent(
                    required: ['success', 'watch'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'watch',
                            type: 'object',
                            required: ['id', 'url', 'title', 'preview', 'fetchedAt', 'created', 'updated', 'body'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'url', type: 'string'),
                                new OA\Property(property: 'title', type: 'string'),
                                new OA\Property(property: 'preview', type: 'string'),
                                new OA\Property(property: 'fetchedAt', type: 'string', nullable: true),
                                new OA\Property(property: 'created', type: 'string', nullable: true),
                                new OA\Property(property: 'updated', type: 'string', nullable: true),
                                new OA\Property(property: 'body', type: 'string'),
                                new OA\Property(property: 'lastDiffText', type: 'string', nullable: true),
                                new OA\Property(property: 'lastError', type: 'string', nullable: true),
                                new OA\Property(property: 'lastFailedAt', type: 'string', nullable: true),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function get(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $watch = $this->service->get($id, (int) $user->getId());
        if (null === $watch) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'watch' => $this->service->toDetail($watch)]);
    }

    #[Route('/{id}/refresh', name: 'refresh', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/url-watches/{id}/refresh',
        summary: 'Fetch the page now, compare to the saved copy, overwrite the snapshot',
        tags: ['URL Watches'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated snapshot and compare status',
                content: new OA\JsonContent(
                    required: ['success', 'watch', 'compare'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'watch',
                            type: 'object',
                            required: ['id', 'url', 'title', 'preview', 'fetchedAt', 'created', 'updated', 'body'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'url', type: 'string'),
                                new OA\Property(property: 'title', type: 'string'),
                                new OA\Property(property: 'preview', type: 'string'),
                                new OA\Property(property: 'fetchedAt', type: 'string', nullable: true),
                                new OA\Property(property: 'created', type: 'string', nullable: true),
                                new OA\Property(property: 'updated', type: 'string', nullable: true),
                                new OA\Property(property: 'body', type: 'string'),
                                new OA\Property(property: 'lastDiffText', type: 'string', nullable: true),
                                new OA\Property(property: 'lastError', type: 'string', nullable: true),
                                new OA\Property(property: 'lastFailedAt', type: 'string', nullable: true),
                            ]
                        ),
                        new OA\Property(
                            property: 'compare',
                            type: 'object',
                            required: ['status', 'diffText'],
                            properties: [
                                new OA\Property(property: 'status', type: 'string', example: 'changed', description: 'first_save, unchanged, or changed'),
                                new OA\Property(property: 'diffText', type: 'string'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Fetch failed'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function refresh(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        try {
            $result = $this->service->refresh($id, (int) $user->getId());
        } catch (UrlWatchNotFoundException) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        } catch (UrlWatchFetchFailedException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, ...$this->service->toComparePayload($result)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/url-watches/{id}',
        summary: 'Stop watching a page and delete the saved copy',
        tags: ['URL Watches'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Deleted',
                content: new OA\JsonContent(
                    required: ['success'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        if (!$this->service->delete($id, (int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true]);
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->routingConfig->isFeatureEnabled(
            MultitaskRoutingConfig::KEY_URL_FETCH_ENABLED,
            (int) $user->getId(),
            true,
        )) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return null;
    }
}
