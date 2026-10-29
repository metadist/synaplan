<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PlatformInstance;
use App\Exception\CloudDeliveryException;
use App\Service\PlatformLink\CloudDeliveryService;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformInstanceSecretException;
use App\Service\PlatformLink\PlatformInstanceService;
use App\Service\PlatformLink\PlatformLinksConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/platform-links/deliveries', name: 'platform_link_deliveries_')]
#[OA\Tag(name: 'Platform Links')]
final class CloudDeliveryController extends AbstractController
{
    public function __construct(
        private PlatformLinksConfig $platformLinksConfig,
        private PlatformInstanceService $instances,
        private CloudDeliveryService $deliveries,
    ) {
    }

    #[Route('/{id}/claim', name: 'claim', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/platform-links/deliveries/{id}/claim',
        operationId: 'claimCloudDelivery',
        summary: 'Claim a queued copy and receive a one-time download path',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Claimed'),
            new OA\Response(response: 403, description: 'Wrong instance'),
            new OA\Response(response: 404, description: 'Unknown delivery'),
            new OA\Response(response: 409, description: 'Already claimed'),
            new OA\Response(response: 410, description: 'Expired'),
            new OA\Response(response: 429, description: 'Too many failed claims'),
        ]
    )]
    public function claim(int $id, Request $request): JsonResponse
    {
        $this->guard();
        try {
            return $this->json($this->deliveries->claim($this->instance($request), $id));
        } catch (CloudDeliveryException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode], $e->status);
        }
    }

    #[Route('/{id}/file', name: 'download', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/platform-links/deliveries/{id}/file',
        operationId: 'downloadCloudDelivery',
        summary: 'Download a claimed copy once',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'ticket', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File bytes'),
            new OA\Response(response: 405, description: 'A header-only request cannot take the file'),
            new OA\Response(response: 410, description: 'Ticket already used or unknown'),
        ]
    )]
    public function download(int $id, Request $request): Response
    {
        $this->guard();
        try {
            $file = $this->deliveries->download(
                $this->instance($request),
                $id,
                (string) $request->query->get('ticket', ''),
                $request->isMethod('GET'),
            );
        } catch (CloudDeliveryException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode], $e->status);
        }
        $response = new BinaryFileResponse($file['path']);
        $response->headers->set('Content-Type', '' !== $file['mime'] ? $file['mime'] : 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['name']);

        return $response;
    }

    #[Route('/{id}/result', name: 'result', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/platform-links/deliveries/{id}/result',
        operationId: 'recordCloudDeliveryResult',
        summary: 'Record that a copy was written or failed',
        tags: ['Platform Links'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Instance-Id', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Instance-Secret', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Current state'),
            new OA\Response(response: 403, description: 'Wrong instance'),
            new OA\Response(response: 404, description: 'Unknown delivery'),
        ]
    )]
    public function result(int $id, Request $request): JsonResponse
    {
        $this->guard();
        $body = json_decode($request->getContent(), true);
        try {
            return $this->json($this->deliveries->result($this->instance($request), $id, is_array($body) ? $body : []));
        } catch (CloudDeliveryException $e) {
            return $this->json(['success' => false, 'error' => $e->errorCode], $e->status);
        }
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
