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
    #[OA\Post(path: '/api/v1/platform-links/pickups/{id}/claim', operationId: 'claimCloudPickup', summary: 'Claim a pickup and receive its selector', tags: ['Platform Links'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'Claimed'),
        new OA\Response(response: 403, description: 'Wrong instance'),
        new OA\Response(response: 404, description: 'Unknown pickup'),
        new OA\Response(response: 409, description: 'Already claimed'),
        new OA\Response(response: 410, description: 'Expired'),
    ])]
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
    #[OA\Post(path: '/api/v1/platform-links/pickups/{id}/listing', operationId: 'listCloudPickup', summary: 'Send a file listing and learn what to upload', tags: ['Platform Links'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [new OA\Response(response: 200, description: 'Upload, reuse and skip lists')])]
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
    #[OA\Post(path: '/api/v1/platform-links/pickups/{id}/files', operationId: 'uploadCloudPickupFile', summary: 'Upload one file into a pickup', tags: ['Platform Links'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [new OA\Response(response: 200, description: 'Stored')])]
    public function files(int $id, Request $request): JsonResponse
    {
        $this->guard();
        $upload = $request->files->get('file');
        $remoteId = (string) $request->request->get('id', '');
        $version = (string) $request->request->get('version', '');
        $name = $upload instanceof UploadedFile ? $upload->getClientOriginalName() : '';
        $raw = $upload instanceof UploadedFile ? file_get_contents($upload->getPathname()) : false;
        $bytes = is_string($raw) ? $raw : '';
        $mime = $upload instanceof UploadedFile ? ($upload->getMimeType() ?? 'application/octet-stream') : 'application/octet-stream';

        try {
            $fileId = $this->pickups->storeFile($this->instance($request), $id, $remoteId, $version, $bytes, $name, $mime);
        } catch (CloudDeliveryException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode], $e->status);
        }

        return $this->json(['file_id' => $fileId]);
    }

    #[Route('/pickups/{id}/result', name: 'result', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(path: '/api/v1/platform-links/pickups/{id}/result', operationId: 'recordCloudPickupResult', summary: 'Close a pickup', tags: ['Platform Links'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [new OA\Response(response: 200, description: 'Current state')])]
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
    #[OA\Put(path: '/api/v1/platform-links/read-scope', operationId: 'reportCloudReadScope', summary: 'Report what a person lets Synaplan read', tags: ['Platform Links'], responses: [
        new OA\Response(response: 200, description: 'Saved'),
        new OA\Response(response: 404, description: 'Unknown person'),
    ])]
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
