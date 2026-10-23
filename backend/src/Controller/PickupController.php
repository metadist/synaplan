<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PlatformInstance;
use App\Exception\CloudDeliveryException;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformInstanceSecretException;
use App\Service\PlatformLink\PickupService;
use App\Service\PlatformLink\PlatformInstanceService;
use App\Service\PlatformLink\PlatformLinksConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/platform-links', name: 'platform_link_pickups_')]
#[OA\Tag(name: 'Platform Links')]
final class PickupController extends AbstractController
{
    public function __construct(
        private PlatformLinksConfig $platformLinksConfig,
        private PlatformInstanceService $instances,
        private PickupService $pickups,
    ) {
    }

    #[Route('/pickups/{id}/claim', name: 'claim', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/platform-links/pickups/{id}/claim',
        operationId: 'claimCloudPickup',
        summary: 'Claim a pickup and receive its selector',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Claimed', content: new OA\JsonContent(required: ['id', 'external_id', 'mode', 'selector', 'read_scope', 'limits', 'expires_at'], properties: [
                new OA\Property(property: 'id', type: 'string', example: '9'),
                new OA\Property(property: 'external_id', type: 'string', example: 'ada'),
                new OA\Property(property: 'mode', type: 'string', enum: ['list', 'fetch']),
                new OA\Property(property: 'selector', type: 'object'),
                new OA\Property(property: 'read_scope', type: 'object'),
                new OA\Property(property: 'limits', type: 'object', properties: [
                    new OA\Property(property: 'max_files', type: 'integer', example: 50),
                    new OA\Property(property: 'max_bytes', type: 'integer', example: 209715200),
                    new OA\Property(property: 'max_file_bytes', type: 'integer', example: 104857600),
                ]),
                new OA\Property(property: 'expires_at', type: 'integer'),
            ])),
            new OA\Response(response: 403, description: 'Wrong instance'),
            new OA\Response(response: 404, description: 'Unknown pickup'),
            new OA\Response(response: 409, description: 'Already claimed'),
            new OA\Response(response: 410, description: 'Expired'),
        ]
    )]
    public function claim(int $id, Request $request): JsonResponse
    {
        $this->guard();

        try {
            return $this->json($this->pickups->claim($this->instance($request), $id));
        } catch (CloudDeliveryException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode], $e->status);
        }
    }

    #[Route('/pickups/{id}/listing', name: 'listing', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/platform-links/pickups/{id}/listing',
        operationId: 'listCloudPickup',
        summary: 'Send a file listing and learn what to upload',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['items'], properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(required: ['id'], properties: [
                new OA\Property(property: 'id', type: 'string'),
                new OA\Property(property: 'version', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'folder', type: 'string'),
                new OA\Property(property: 'size', type: 'integer'),
                new OA\Property(property: 'mime', type: 'string'),
            ])),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Upload, reuse and skip lists', content: new OA\JsonContent(required: ['upload', 'reuse', 'skip'], properties: [
                new OA\Property(property: 'upload', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'reuse', type: 'array', items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'id', type: 'string'),
                    new OA\Property(property: 'file_id', type: 'integer'),
                ])),
                new OA\Property(property: 'skip', type: 'array', items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'id', type: 'string'),
                    new OA\Property(property: 'reason', type: 'string', enum: ['too_large', 'type_not_read', 'over_limit']),
                ])),
            ])),
        ]
    )]
    public function listing(int $id, Request $request): JsonResponse
    {
        $this->guard();
        $body = json_decode($request->getContent(), true);
        $items = is_array($body) && is_array($body['items'] ?? null) ? $body['items'] : [];

        try {
            return $this->json($this->pickups->listing($this->instance($request), $id, $items));
        } catch (CloudDeliveryException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode], $e->status);
        }
    }

    #[Route('/pickups/{id}/files', name: 'files', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/platform-links/pickups/{id}/files',
        operationId: 'uploadCloudPickupFile',
        summary: 'Upload one file into a pickup',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file', 'id', 'version'], properties: [
            new OA\Property(property: 'file', type: 'string', format: 'binary'),
            new OA\Property(property: 'id', type: 'string', description: 'Remote id from the upload list'),
            new OA\Property(property: 'version', type: 'string'),
        ]))),
        responses: [
            new OA\Response(response: 200, description: 'Stored', content: new OA\JsonContent(required: ['file_id'], properties: [
                new OA\Property(property: 'file_id', type: 'integer'),
            ])),
            new OA\Response(response: 409, description: 'Not requested, too large, or a names-only pickup'),
        ]
    )]
    public function files(int $id, Request $request): JsonResponse
    {
        $this->guard();
        try {
            $instance = $this->instance($request);
            $upload = $request->files->get('file');
            if (!$upload instanceof UploadedFile || !$upload->isValid()) {
                return $this->json(['success' => false, 'error' => 'not_requested'], Response::HTTP_CONFLICT);
            }
            $raw = file_get_contents($upload->getPathname());
            if (!is_string($raw)) {
                return $this->json(['success' => false, 'error' => 'not_requested'], Response::HTTP_CONFLICT);
            }
            $fileId = $this->pickups->storeFile(
                $instance,
                $id,
                (string) $request->request->get('id', ''),
                (string) $request->request->get('version', ''),
                $raw,
                $upload->getClientOriginalName(),
                $upload->getMimeType() ?? 'application/octet-stream',
            );
        } catch (CloudDeliveryException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode], $e->status);
        }

        return $this->json(['file_id' => $fileId]);
    }

    #[Route('/pickups/{id}/result', name: 'result', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/platform-links/pickups/{id}/result',
        operationId: 'recordCloudPickupResult',
        summary: 'Close a pickup',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status'], properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['done', 'partial', 'failed']),
            new OA\Property(property: 'listed', type: 'integer'),
            new OA\Property(property: 'sent', type: 'integer'),
            new OA\Property(property: 'skipped', type: 'array', items: new OA\Items(type: 'object')),
            new OA\Property(property: 'error_code', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Current state', content: new OA\JsonContent(required: ['state'], properties: [
                new OA\Property(property: 'state', type: 'string', enum: ['queued', 'claimed', 'done', 'partial', 'failed']),
            ])),
        ]
    )]
    public function result(int $id, Request $request): JsonResponse
    {
        $this->guard();
        $body = json_decode($request->getContent(), true);

        try {
            return $this->json($this->pickups->result($this->instance($request), $id, is_array($body) ? $body : []));
        } catch (CloudDeliveryException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode], $e->status);
        }
    }

    #[Route('/read-scope', name: 'read_scope', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/platform-links/read-scope',
        operationId: 'reportCloudReadScope',
        summary: 'Report what a person lets Synaplan read',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['external_id', 'mode', 'folders'], properties: [
            new OA\Property(property: 'external_id', type: 'string'),
            new OA\Property(property: 'mode', type: 'string', enum: ['watched', 'allowed', 'all']),
            new OA\Property(property: 'folders', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'id', type: 'string'),
                new OA\Property(property: 'path', type: 'string'),
            ])),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 404, description: 'Unknown person'),
        ]
    )]
    public function readScope(Request $request): JsonResponse
    {
        $this->guard();
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        try {
            $this->pickups->reportScope(
                $this->instance($request),
                is_string($body['external_id'] ?? null) ? $body['external_id'] : '',
                is_string($body['mode'] ?? null) ? $body['mode'] : '',
                is_array($body['folders'] ?? null) ? $body['folders'] : [],
            );
        } catch (CloudDeliveryException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode], $e->status);
        }

        return $this->json(['success' => true]);
    }

    private function guard(): void
    {
        if (!$this->platformLinksConfig->isEnabled(null)) {
            throw new NotFoundHttpException();
        }
    }

    private function instance(Request $request): PlatformInstance
    {
        try {
            $instance = $this->instances->requireInstance((string) $request->headers->get('X-Instance-Id', ''));
            $this->instances->assertSecret($instance, (string) $request->headers->get('X-Instance-Secret', ''));
        } catch (PlatformInstanceNotFoundException) {
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, 'unknown_delivery');
        } catch (PlatformInstanceSecretException) {
            throw new CloudDeliveryException(Response::HTTP_UNAUTHORIZED, 'unauthorized');
        }

        return $instance;
    }
}
