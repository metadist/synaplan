<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ResearchAdminView;
use App\DTO\ResearchBudgetView;
use App\DTO\ResearchErrorView;
use App\DTO\ResearchSampleView;
use App\Entity\User;
use App\Service\Research\Run\ResearchAdminService;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchStoreUnavailable;
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
        description: 'Send only what changes. A left-out value keeps what is stored; 0 or null resets a model to the recommended one.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'enabled', type: 'boolean', example: true),
                new OA\Property(property: 'readingModelId', type: 'integer', nullable: true, example: 12),
                new OA\Property(property: 'checkingModelId', type: 'integer', nullable: true, example: 0),
                new OA\Property(property: 'limits', type: 'object', properties: [
                    new OA\Property(property: 'maxActiveRuns', type: 'integer', minimum: 1, maximum: 100, example: 5),
                    new OA\Property(property: 'maxActiveThorough', type: 'integer', minimum: 0, maximum: 10, example: 1),
                ]),
                new OA\Property(property: 'budgets', type: 'object', properties: [
                    new OA\Property(property: 'quick', ref: new Model(type: ResearchBudgetView::class)),
                    new OA\Property(property: 'standard', ref: new Model(type: ResearchBudgetView::class)),
                    new OA\Property(property: 'thorough', ref: new Model(type: ResearchBudgetView::class)),
                ]),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 200, description: 'The research tab after the change', content: new OA\JsonContent(ref: new Model(type: ResearchAdminView::class)))]
    #[OA\Response(response: 400, description: 'A value is missing its form or is out of range. Nothing was stored.', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 409, description: 'The switch is pinned by the server. Nothing was stored.', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
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
    #[OA\Response(response: 409, description: 'Research is off, or a sample is already running', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    #[OA\Response(response: 429, description: 'Too many research runs are going', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
    #[OA\Response(response: 503, description: 'The research store did not answer. The sample did not start.', content: new OA\JsonContent(ref: new Model(type: ResearchErrorView::class)))]
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
        } catch (ResearchStoreUnavailable $unavailable) {
            return $this->json(
                ['error' => ['code' => 'unavailable', 'field' => null, 'message' => $unavailable->getMessage()]],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
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
