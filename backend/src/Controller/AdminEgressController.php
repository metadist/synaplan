<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Egress\EgressAllowListEditor;
use App\Service\Egress\EgressAllowListException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Allow and Remove on Admin › Capacity › Outbound (journey J-AU-3). Both
 * edit the administrator's outbound list, the same one as Admin › System
 * settings › Outbound traffic, and apply without a restart.
 */
#[Route('/api/v1/admin/egress/allow')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin Egress')]
final class AdminEgressController extends AbstractController
{
    public function __construct(
        private readonly EgressAllowListEditor $editor,
    ) {
    }

    #[Route('', name: 'admin_egress_allow_add', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/egress/allow',
        summary: 'Allow one outbound destination',
        description: 'Adds a host (`api.example.com`), a host and port (`api.example.com:8443`) or a wildcard (`*.example.org`) to the outbound list. Adding an entry that is already listed changes nothing.',
        security: [['Bearer' => []]],
        tags: ['Admin Egress'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['rule'],
                properties: [new OA\Property(property: 'rule', type: 'string', example: 'search.example.org')],
            ),
        ),
    )]
    #[OA\Response(response: 200, description: 'The outbound list after the change', content: new OA\JsonContent(
        required: ['success', 'rules'],
        properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'rules', type: 'array', description: 'Every entry an administrator added, in order.', items: new OA\Items(type: 'string'), example: ['search.example.org']),
        ],
    ))]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 422, description: 'Not a host, host:port or *.domain entry', content: new OA\JsonContent(
        required: ['success', 'error'],
        properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'error', type: 'string', enum: ['invalid_rule', 'not_saved'], example: 'invalid_rule'),
        ],
    ))]
    #[OA\Response(response: 500, description: 'The list could not be saved', content: new OA\JsonContent(
        required: ['success', 'error'],
        properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'error', type: 'string', enum: ['invalid_rule', 'not_saved'], example: 'invalid_rule'),
        ],
    ))]
    public function add(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return $this->change($request, fn (string $rule): array => $this->editor->add($rule, (int) $user->getId()));
    }

    #[Route('', name: 'admin_egress_allow_remove', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/admin/egress/allow',
        summary: 'Remove one outbound destination from the list',
        description: 'Removes an entry an administrator added. Destinations Synaplan itself needs are not on this list and cannot be removed. Removing an entry that is not listed changes nothing.',
        security: [['Bearer' => []]],
        tags: ['Admin Egress'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['rule'],
                properties: [new OA\Property(property: 'rule', type: 'string', example: 'search.example.org')],
            ),
        ),
    )]
    #[OA\Response(response: 200, description: 'The outbound list after the change', content: new OA\JsonContent(
        required: ['success', 'rules'],
        properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'rules', type: 'array', description: 'Every entry an administrator added, in order.', items: new OA\Items(type: 'string'), example: ['search.example.org']),
        ],
    ))]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 422, description: 'Not a host, host:port or *.domain entry', content: new OA\JsonContent(
        required: ['success', 'error'],
        properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'error', type: 'string', enum: ['invalid_rule', 'not_saved'], example: 'invalid_rule'),
        ],
    ))]
    #[OA\Response(response: 500, description: 'The list could not be saved', content: new OA\JsonContent(
        required: ['success', 'error'],
        properties: [
            new OA\Property(property: 'success', type: 'boolean', example: false),
            new OA\Property(property: 'error', type: 'string', enum: ['invalid_rule', 'not_saved'], example: 'invalid_rule'),
        ],
    ))]
    public function remove(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return $this->change($request, fn (string $rule): array => $this->editor->remove($rule, (int) $user->getId()));
    }

    /**
     * @param callable(string): list<string> $apply
     */
    private function change(Request $request, callable $apply): JsonResponse
    {
        $rule = $request->getPayload()->get('rule');
        if (!\is_string($rule) || '' === trim($rule)) {
            return $this->json(['success' => false, 'error' => EgressAllowListException::INVALID_RULE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $rules = $apply($rule);
        } catch (EgressAllowListException $e) {
            return $this->json(
                ['success' => false, 'error' => $e->getMessage()],
                $e->isInvalidRule() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->json(['success' => true, 'rules' => $rules]);
    }
}
