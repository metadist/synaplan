<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Agent\AgentAccess;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Iam\Permission;
use App\Service\WhatsApp\WhatsAppAgentBinding;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Channel-side binding of the user's WhatsApp number to an assistant
 * (the `whatsapp` trigger event seen from the Channels page).
 */
#[Route('/api/v1/channels/whatsapp/assistant', name: 'api_whatsapp_assistant_')]
#[OA\Tag(name: 'WhatsApp')]
final class WhatsAppAssistantController extends AbstractController
{
    public function __construct(
        private readonly WhatsAppAgentBinding $binding,
        private readonly AgentConfig $agentConfig,
        private readonly AgentAccess $agentAccess,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/channels/whatsapp/assistant',
        summary: 'Get the WhatsApp assistant binding',
        security: [['Bearer' => []]],
        tags: ['WhatsApp']
    )]
    #[OA\Response(
        response: 200,
        description: 'Current binding',
        content: new OA\JsonContent(
            required: ['success', 'agentId'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'agentId', type: 'integer', nullable: true, example: 12),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getBinding(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['success' => true, 'agentId' => $this->binding->get((int) $user->getId())]);
    }

    #[Route('', name: 'put', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/channels/whatsapp/assistant',
        summary: 'Bind WhatsApp inbound messages to an assistant',
        description: 'Send `agentId: null` to remove the binding. The assistant must be usable by the caller and Agent Builder must be enabled.',
        security: [['Bearer' => []]],
        tags: ['WhatsApp']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'agentId', type: 'integer', nullable: true, example: 12),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Updated binding',
        content: new OA\JsonContent(
            required: ['success', 'agentId'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'agentId', type: 'integer', nullable: true, example: 12),
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Assistants disabled or assistant not usable',
        content: new OA\JsonContent(
            required: ['success', 'error'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'You cannot use this assistant'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function putBinding(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        $userId = (int) $user->getId();
        $raw = $request->toArray()['agentId'] ?? null;
        if (null === $raw || '' === $raw || 0 === $raw) {
            $this->binding->set($userId, null);

            return $this->json(['success' => true, 'agentId' => null]);
        }
        $id = (int) $raw;
        if ($id < 1 || !$this->agentConfig->isEnabled($userId)) {
            return $this->json(['success' => false, 'error' => 'You cannot use this assistant'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->agentAccess->require($user, $id, Permission::Use);
        } catch (AgentNotAccessibleException) {
            return $this->json(['success' => false, 'error' => 'You cannot use this assistant'], Response::HTTP_BAD_REQUEST);
        }
        $this->binding->set($userId, $id);

        return $this->json(['success' => true, 'agentId' => $id]);
    }
}
