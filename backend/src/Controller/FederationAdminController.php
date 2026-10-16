<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Federation\FederationException;
use App\Service\Federation\FederationLinkService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/federation', name: 'api_federation_')]
#[OA\Tag(name: 'Federation')]
#[IsGranted('ROLE_ADMIN')]
final class FederationAdminController extends AbstractController
{
    public function __construct(
        private readonly FederationLinkService $links,
    ) {
    }

    #[Route('/membership', name: 'membership', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/federation/membership', summary: 'Whether this server is open to partners', tags: ['Federation'], responses: [
        new OA\Response(response: 200, description: 'Membership', content: new OA\JsonContent(
            required: ['reachable', 'opened', 'domain', 'name', 'fingerprint', 'sodium'],
            properties: [
                new OA\Property(property: 'reachable', type: 'boolean'),
                new OA\Property(property: 'opened', type: 'boolean'),
                new OA\Property(property: 'domain', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'fingerprint', type: 'string'),
                new OA\Property(property: 'sodium', type: 'boolean'),
                new OA\Property(property: 'pageUrl', type: 'string'),
            ]
        )),
    ])]
    public function membership(): JsonResponse
    {
        return $this->json($this->links->membership());
    }

    #[Route('/membership/open', name: 'membership_open', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/federation/membership/open', summary: 'Open this server to partners', tags: ['Federation'], responses: [
        new OA\Response(response: 200, description: 'Opened', content: new OA\JsonContent(
            required: ['reachable', 'opened', 'domain', 'name', 'fingerprint', 'sodium'],
            properties: [
                new OA\Property(property: 'reachable', type: 'boolean'),
                new OA\Property(property: 'opened', type: 'boolean'),
                new OA\Property(property: 'domain', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'fingerprint', type: 'string'),
                new OA\Property(property: 'sodium', type: 'boolean'),
                new OA\Property(property: 'pageUrl', type: 'string'),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )),
        new OA\Response(response: 409, description: 'This server cannot be reached'),
    ])]
    public function open(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $body = $this->body($request);
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';

        return $this->run(fn (): array => $this->links->open($this->actor($user), $name));
    }

    #[Route('/membership/close', name: 'membership_close', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/federation/membership/close', summary: 'Close this server to partners', tags: ['Federation'], responses: [
        new OA\Response(response: 200, description: 'Closed', content: new OA\JsonContent(
            required: ['reachable', 'opened', 'domain', 'name', 'fingerprint', 'sodium', 'message'],
            properties: [
                new OA\Property(property: 'reachable', type: 'boolean'),
                new OA\Property(property: 'opened', type: 'boolean'),
                new OA\Property(property: 'domain', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'fingerprint', type: 'string'),
                new OA\Property(property: 'sodium', type: 'boolean'),
                new OA\Property(property: 'pageUrl', type: 'string'),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )),
    ])]
    public function close(#[CurrentUser] ?User $user): JsonResponse
    {
        return $this->run(fn (): array => $this->links->close($this->actor($user)));
    }

    #[Route('/partners', name: 'partners', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/federation/partners', summary: 'List partner connections', tags: ['Federation'], responses: [
        new OA\Response(response: 200, description: 'Partners', content: new OA\JsonContent(
            required: ['partners'],
            properties: [
                new OA\Property(property: 'partners', type: 'array', items: new OA\Items(
                    required: ['id', 'status', 'name', 'domain'],
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'domain', type: 'string'),
                        new OA\Property(property: 'pausedBy', type: 'string', nullable: true),
                        new OA\Property(property: 'expiresAt', type: 'integer', nullable: true),
                    ]
                )),
            ]
        )),
    ])]
    public function partners(): JsonResponse
    {
        return $this->json(['partners' => $this->links->listPartners()]);
    }

    #[Route('/invites', name: 'invites_create', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/federation/invites', summary: 'Create a one-time invite link', tags: ['Federation'], responses: [
        new OA\Response(response: 200, description: 'Invite', content: new OA\JsonContent(
            required: ['pasteUrl', 'expiresAt'],
            properties: [
                new OA\Property(property: 'pasteUrl', type: 'string'),
                new OA\Property(property: 'expiresAt', type: 'integer'),
            ]
        )),
    ])]
    public function createInvite(#[CurrentUser] ?User $user): JsonResponse
    {
        return $this->run(fn (): array => $this->links->createInvite($this->actor($user)));
    }

    #[Route('/partners/accept', name: 'partners_accept', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/federation/partners/accept', summary: 'Accept an invite from another server', tags: ['Federation'], responses: [
        new OA\Response(response: 200, description: 'Connected', content: new OA\JsonContent(
            required: ['partner', 'peerConfirmed', 'message'],
            properties: [
                new OA\Property(property: 'peerConfirmed', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'partner', required: ['id', 'status', 'name', 'domain'], properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'domain', type: 'string'),
                    new OA\Property(property: 'pausedBy', type: 'string', nullable: true),
                    new OA\Property(property: 'expiresAt', type: 'integer', nullable: true),
                ], type: 'object'),
            ]
        )),
    ])]
    public function accept(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $body = $this->body($request);
        $url = is_string($body['inviteUrl'] ?? null) ? $body['inviteUrl'] : '';

        return $this->run(fn (): array => $this->links->acceptInvite($this->actor($user), $url));
    }

    #[Route('/partners/{id}/pause', name: 'partners_pause', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(path: '/api/v1/federation/partners/{id}/pause', summary: 'Pause a partner connection', tags: ['Federation'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'Paused', content: new OA\JsonContent(
            required: ['partner', 'peerConfirmed', 'message'],
            properties: [
                new OA\Property(property: 'peerConfirmed', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'partner', required: ['id', 'status', 'name', 'domain'], properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'domain', type: 'string'),
                    new OA\Property(property: 'pausedBy', type: 'string', nullable: true),
                    new OA\Property(property: 'expiresAt', type: 'integer', nullable: true),
                ], type: 'object'),
            ]
        )),
    ])]
    public function pause(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->run(fn (): array => $this->links->pause($this->actor($user), $id));
    }

    #[Route('/partners/{id}/resume', name: 'partners_resume', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(path: '/api/v1/federation/partners/{id}/resume', summary: 'Resume a partner connection', tags: ['Federation'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'Resumed', content: new OA\JsonContent(
            required: ['partner', 'peerConfirmed', 'message'],
            properties: [
                new OA\Property(property: 'peerConfirmed', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'partner', required: ['id', 'status', 'name', 'domain'], properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'domain', type: 'string'),
                    new OA\Property(property: 'pausedBy', type: 'string', nullable: true),
                    new OA\Property(property: 'expiresAt', type: 'integer', nullable: true),
                ], type: 'object'),
            ]
        )),
    ])]
    public function resume(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->run(fn (): array => $this->links->resume($this->actor($user), $id));
    }

    #[Route('/partners/{id}', name: 'partners_disconnect', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(path: '/api/v1/federation/partners/{id}', summary: 'Disconnect a partner or remove an invite', tags: ['Federation'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [
        new OA\Response(response: 200, description: 'Disconnected', content: new OA\JsonContent(
            required: ['partner', 'peerConfirmed', 'message'],
            properties: [
                new OA\Property(property: 'peerConfirmed', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'partner', required: ['id', 'status', 'name', 'domain'], properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'domain', type: 'string'),
                    new OA\Property(property: 'pausedBy', type: 'string', nullable: true),
                    new OA\Property(property: 'expiresAt', type: 'integer', nullable: true),
                ], type: 'object'),
            ]
        )),
    ])]
    public function disconnect(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->run(fn (): array => $this->links->disconnect($this->actor($user), $id));
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function run(callable $action): JsonResponse
    {
        try {
            return $this->json($action());
        } catch (FederationException $e) {
            return $this->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }

    private function actor(?User $user): int
    {
        $id = $user?->getId();
        if (null === $id) {
            throw $this->createAccessDeniedException();
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
