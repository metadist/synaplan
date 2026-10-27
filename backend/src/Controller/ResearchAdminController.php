<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ResearchAdminView;
use App\DTO\ResearchSampleView;
use App\Entity\User;
use App\Service\Research\Run\ResearchAdminService;
use App\Service\Research\Run\ResearchRejected;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/research')]
#[OA\Tag(name: 'Admin Research')]
final class ResearchAdminController extends AbstractController
{
    public function __construct(
        private readonly ResearchAdminService $research,
    ) {
    }

    #[Route('', name: 'admin_research_show', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/research',
        operationId: 'getAdminResearch',
        summary: 'Research switch, models and limits',
        security: [['Bearer' => []]],
        tags: ['Admin Research']
    )]
    #[OA\Response(response: 200, description: 'The research tab', content: new OA\JsonContent(ref: new Model(type: ResearchAdminView::class)))]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function show(#[CurrentUser] ?User $user): JsonResponse
    {
        $admin = $this->admin($user);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->json($this->research->describe($admin));
    }

    #[Route('', name: 'admin_research_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/admin/research',
        operationId: 'putAdminResearch',
        summary: 'Change the research switch, models or limits',
        security: [['Bearer' => []]],
        tags: ['Admin Research']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'enabled', type: 'boolean'),
                new OA\Property(property: 'readingModelId', type: 'integer', nullable: true),
                new OA\Property(property: 'checkingModelId', type: 'integer', nullable: true),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 200, description: 'The research tab after the change', content: new OA\JsonContent(ref: new Model(type: ResearchAdminView::class)))]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 409, description: 'The switch is pinned by the server')]
    public function update(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $admin = $this->admin($user);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => ['code' => 'invalid', 'field' => null, 'message' => 'Send the research settings as one object.']], Response::HTTP_BAD_REQUEST);
        }
        try {
            return $this->json($this->research->update($admin, $body, (string) $request->getClientIp()));
        } catch (ResearchRejected $rejected) {
            return $this->json($rejected->body(), $rejected->status);
        }
    }

    #[Route('/sample', name: 'admin_research_sample', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/research/sample',
        operationId: 'postAdminResearchSample',
        summary: 'Start one short sample research',
        security: [['Bearer' => []]],
        tags: ['Admin Research']
    )]
    #[OA\Response(response: 200, description: 'The sample has started', content: new OA\JsonContent(ref: new Model(type: ResearchSampleView::class)))]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 409, description: 'Research is off')]
    public function sample(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $admin = $this->admin($user);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        try {
            return $this->json($this->research->startSample($admin, (string) $request->getClientIp()));
        } catch (ResearchRejected $rejected) {
            return $this->json($rejected->body(), $rejected->status);
        }
    }

    private function admin(?User $user): User|JsonResponse
    {
        if (!$user instanceof User || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return $user;
    }
}
