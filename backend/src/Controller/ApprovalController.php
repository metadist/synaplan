<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Tool\ApprovalResponse;
use App\Entity\User;
use App\Message\ResumeApprovalCommand;
use App\Service\AgentTemplate\AgentPublishReview;
use App\Service\AgentTemplate\Exception\AgentPublishReviewException;
use App\Service\Email\MailerConfig;
use App\Service\Tool\ApprovalNotFoundException;
use App\Service\Tool\ApprovalService;
use App\Service\Tool\ToolsConfig;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/approvals', name: 'api_approvals_')]
#[OA\Tag(name: 'Approvals')]
final class ApprovalController extends AbstractController
{
    public function __construct(
        private ApprovalService $approvals,
        private ToolsConfig $toolsConfig,
        private MessageBusInterface $bus,
        private MailerConfig $mailerConfig,
        private AgentPublishReview $reviews,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/approvals',
        summary: 'List approvals for the current user',
        tags: ['Approvals'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'decided'], example: 'pending')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Approvals',
                content: new OA\JsonContent(
                    required: ['success', 'approvals', 'pendingCount'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'pendingCount', type: 'integer', example: 1),
                        new OA\Property(property: 'approvals', type: 'array', items: new OA\Items(ref: new Model(type: ApprovalResponse::class))),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Approvals disabled'),
        ]
    )]
    public function list(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $status = (string) $request->query->get('status', 'pending');
        $approvals = $this->approvals->listFor($user, $status);
        foreach ($this->reviews->forReviewer($user, $status) as $approval) {
            $row = $this->approvals->toArray($approval);
            $row['canDecide'] = true;
            $approvals[] = $row;
        }

        return $this->json([
            'success' => true,
            'pendingCount' => $this->approvals->pendingCount($user) + $this->reviews->countForReviewer($user),
            'approvals' => $approvals,
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/approvals/{id}/approve',
        summary: 'Approve a pending tool call',
        tags: ['Approvals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'alwaysAllow', type: 'boolean', example: false),
            new OA\Property(property: 'assistantKey', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Approved',
                content: new OA\JsonContent(
                    required: ['success', 'approval'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'approval', ref: new Model(type: ApprovalResponse::class)),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function approve(int $id, #[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        if ($this->reviews->isPublishReview($id)) {
            return $this->decidePublishReview($id, $user, true, null);
        }
        $payload = json_decode($request->getContent() ?: '{}', true);
        $payload = is_array($payload) ? $payload : [];
        try {
            $approval = $this->approvals->approve(
                $id,
                $user,
                true === ($payload['alwaysAllow'] ?? false),
                is_string($payload['assistantKey'] ?? null) ? $payload['assistantKey'] : null,
            );
        } catch (ApprovalNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        $this->bus->dispatch(new ResumeApprovalCommand((int) $approval->getId()));

        return $this->json(['success' => true, 'approval' => $this->approvals->toArray($approval)]);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[OA\Post(
        path: '/api/v1/approvals/{id}/reject',
        summary: 'Reject a pending tool call',
        tags: ['Approvals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'reason', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Rejected',
                content: new OA\JsonContent(
                    required: ['success', 'approval'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'approval', ref: new Model(type: ApprovalResponse::class)),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function reject(int $id, #[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $payload = json_decode($request->getContent() ?: '{}', true);
        $payload = is_array($payload) ? $payload : [];
        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : null;
        if ($this->reviews->isPublishReview($id)) {
            return $this->decidePublishReview($id, $user, false, $reason);
        }
        try {
            $approval = $this->approvals->reject($id, $user, $reason);
        } catch (ApprovalNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'approval' => $this->approvals->toArray($approval)]);
    }

    #[Route('/notify-setting', name: 'notify_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/approvals/notify-setting',
        summary: 'Get approval notification preference',
        tags: ['Approvals'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Setting',
                content: new OA\JsonContent(
                    required: ['success', 'mode'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'mode', type: 'string', enum: ['instant', 'digest']),
                        new OA\Property(property: 'mailConfigured', type: 'boolean'),
                    ]
                )
            ),
        ]
    )]
    public function getNotifySetting(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->json([
            'success' => true,
            'mode' => $this->toolsConfig->notifyMode((int) $user->getId()),
            'mailConfigured' => $this->mailerConfig->isConfigured(),
        ]);
    }

    #[Route('/notify-setting', name: 'notify_patch', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/v1/approvals/notify-setting',
        summary: 'Set approval notification preference',
        tags: ['Approvals'],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(required: ['mode'], properties: [
            new OA\Property(property: 'mode', type: 'string', enum: ['instant', 'digest']),
        ])),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Saved',
                content: new OA\JsonContent(
                    required: ['success', 'mode'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'mode', type: 'string', enum: ['instant', 'digest']),
                    ]
                )
            ),
        ]
    )]
    public function setNotifySetting(#[CurrentUser] ?User $user, Request $request): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $payload = json_decode($request->getContent() ?: '{}', true);
        $mode = is_array($payload) && is_string($payload['mode'] ?? null) ? $payload['mode'] : ToolsConfig::NOTIFY_INSTANT;
        $this->toolsConfig->setNotifyMode((int) $user->getId(), $mode);

        return $this->json([
            'success' => true,
            'mode' => $this->toolsConfig->notifyMode((int) $user->getId()),
        ]);
    }

    private function decidePublishReview(int $id, User $user, bool $approve, ?string $note): JsonResponse
    {
        try {
            $approval = $approve
                ? $this->reviews->approve($id, $user)
                : $this->reviews->sendBack($id, $user, $note);
        } catch (AgentPublishReviewException $e) {
            $status = match ($e->reason) {
                AgentPublishReviewException::FORBIDDEN => Response::HTTP_FORBIDDEN,
                AgentPublishReviewException::SHARING_OFF => Response::HTTP_CONFLICT,
                default => Response::HTTP_NOT_FOUND,
            };

            return $this->json(['success' => false, 'error' => $e->getMessage(), 'code' => $e->reason], $status);
        }
        $row = $this->approvals->toArray($approval);
        $row['canDecide'] = false;

        return $this->json(['success' => true, 'approval' => $row]);
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->toolsConfig->isApprovalsEnabled((int) $user->getId())) {
            return $this->json(['success' => false, 'error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return null;
    }
}
