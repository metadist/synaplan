<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CloudCallerError;
use App\Entity\User;
use App\Exception\CloudDeliveryException;
use App\Service\PlatformLink\CloudCallerResolver;
use App\Service\PlatformLink\CloudDeliveryService;
use App\Service\PlatformLink\PlatformLinksConfig;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/platform-links/deliveries', name: 'platform_link_deliveries_')]
#[OA\Tag(name: 'Platform Links')]
final class CloudDeliveryController extends AbstractController
{
    public function __construct(
        private CloudCallerResolver $callers,
        private PlatformLinksConfig $platformLinksConfig,
        private CloudDeliveryService $deliveries,
    ) {
    }

    #[Route('/{id}/claim', name: 'claim', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/platform-links/deliveries/{id}/claim',
        operationId: 'claimCloudDelivery',
        summary: 'Claim a queued copy and receive a one-time download path',
        tags: ['Platform Links'],
        security: [['Bearer' => []], ['ApiKey' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Claimed'),
            new OA\Response(response: 401, description: CloudCallerError::UNAUTHORIZED, content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
            new OA\Response(response: 403, description: CloudCallerError::FORBIDDEN, content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
            new OA\Response(response: 404, description: 'Unknown delivery, or one of another person (unknown_delivery)', content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
            new OA\Response(response: 409, description: 'Already claimed, or '.CloudCallerError::AMBIGUOUS, content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
            new OA\Response(response: 410, description: 'Expired'),
            new OA\Response(response: 429, description: 'Too many failed claims'),
        ]
    )]
    public function claim(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard();
        try {
            return $this->json($this->deliveries->claim($this->callers->resolve($request, $user), $id));
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
        security: [['Bearer' => []], ['ApiKey' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'ticket', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File bytes'),
            new OA\Response(response: 401, description: CloudCallerError::UNAUTHORIZED, content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
            new OA\Response(response: 403, description: CloudCallerError::FORBIDDEN, content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
            new OA\Response(response: 405, description: 'A header-only request cannot take the file'),
            new OA\Response(response: 409, description: CloudCallerError::AMBIGUOUS, content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
            new OA\Response(response: 410, description: 'Ticket already used or unknown'),
        ]
    )]
    public function download(int $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->guard();
        try {
            $file = $this->deliveries->download(
                $this->callers->resolve($request, $user),
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
        security: [['Bearer' => []], ['ApiKey' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Current state'),
            new OA\Response(response: 401, description: CloudCallerError::UNAUTHORIZED, content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
            new OA\Response(response: 403, description: CloudCallerError::FORBIDDEN, content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
            new OA\Response(response: 404, description: 'Unknown delivery, or one of another person (unknown_delivery)', content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
            new OA\Response(response: 409, description: CloudCallerError::AMBIGUOUS, content: new OA\JsonContent(ref: new Model(type: CloudCallerError::class))),
        ]
    )]
    public function result(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard();
        $body = json_decode($request->getContent(), true);
        try {
            return $this->json($this->deliveries->result($this->callers->resolve($request, $user), $id, is_array($body) ? $body : []));
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
}
