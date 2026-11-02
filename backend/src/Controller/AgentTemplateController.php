<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Agent\AgentConfig;
use App\Service\AgentTemplate\AgentTemplateInstaller;
use App\Service\AgentTemplate\AgentTemplateInstallRequest;
use App\Service\AgentTemplate\AgentTemplateListing;
use App\Service\AgentTemplate\Exception\AgentPublishReviewException;
use App\Service\AgentTemplate\Exception\AgentTemplateForbiddenException;
use App\Service\AgentTemplate\Exception\AgentTemplateNotFoundException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/agent-templates', name: 'api_agent_templates_')]
#[OA\Tag(name: 'Agent templates')]
final class AgentTemplateController extends AbstractController
{
    public function __construct(
        private readonly AgentConfig $config,
        private readonly AgentTemplateListing $listing,
        private readonly AgentTemplateInstaller $installer,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/agent-templates',
        summary: 'List built-in assistant templates',
        description: 'Returns 404 when AGENTS.ENABLED is off. Templates never name a model. peopleCount is present for administrators only.',
        tags: ['Agent templates'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Templates the person can install',
                content: new OA\JsonContent(
                    required: ['success', 'templates'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'peopleCount', type: 'integer', nullable: true, example: 40),
                        new OA\Property(property: 'publishReviewRequired', type: 'boolean', example: false),
                        new OA\Property(property: 'templates', type: 'array', items: new OA\Items(
                            required: ['id', 'version', 'titleKey', 'summaryKey', 'category', 'tryIt', 'requires', 'usedBy', 'updateAvailable'],
                            properties: [
                                new OA\Property(property: 'id', type: 'string', example: 'offer-writer'),
                                new OA\Property(property: 'version', type: 'string', example: '2026.11.1'),
                                new OA\Property(property: 'titleKey', type: 'string', example: 'templates.offerWriter.title'),
                                new OA\Property(property: 'summaryKey', type: 'string', example: 'templates.offerWriter.summary'),
                                new OA\Property(property: 'category', type: 'string', example: 'sales'),
                                new OA\Property(property: 'tryIt', type: 'array', items: new OA\Items(type: 'string')),
                                new OA\Property(property: 'requires', type: 'array', items: new OA\Items(
                                    required: ['kind', 'optional', 'state'],
                                    properties: [
                                        new OA\Property(property: 'kind', type: 'string', example: 'knowledgeFolder'),
                                        new OA\Property(property: 'value', type: 'string', nullable: true, example: 'mail'),
                                        new OA\Property(property: 'hintKey', type: 'string', nullable: true),
                                        new OA\Property(property: 'optional', type: 'boolean', example: false),
                                        new OA\Property(property: 'state', type: 'string', enum: ['ready', 'needsYou', 'unavailable']),
                                        new OA\Property(property: 'action', type: 'string', nullable: true, enum: ['chooseFolder', 'connectMail']),
                                    ],
                                )),
                                new OA\Property(property: 'usedBy', type: 'integer', example: 3),
                                new OA\Property(property: 'installedAgentId', type: 'integer', nullable: true, example: null),
                                new OA\Property(property: 'installedVersion', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'updateAvailable', type: 'boolean', example: false),
                                new OA\Property(property: 'publishReview', type: 'string', nullable: true, enum: ['waiting', 'sent_back']),
                                new OA\Property(property: 'publishReviewNote', type: 'string', nullable: true),
                            ],
                        )),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Assistants are turned off'),
        ],
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);
        $payload = $this->listing->forUser($user);

        return $this->json([
            'success' => true,
            'peopleCount' => $payload['peopleCount'],
            'publishReviewRequired' => $payload['publishReviewRequired'],
            'templates' => $payload['templates'],
        ]);
    }

    #[Route('/{id}/install', name: 'install', methods: ['POST'], requirements: ['id' => '[a-z0-9][a-z0-9-]{0,31}'])]
    #[OA\Post(
        path: '/api/v1/agent-templates/{id}/install',
        summary: 'Install an assistant from a template',
        description: 'Creates a private assistant for the signed-in person and publishes it so they can try it. Administrators may pass audience=everyone to share it. A second install opens the assistant that is already there. The name the client sends is kept.',
        tags: ['Agent templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'offer-writer'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'audience', type: 'string', enum: ['me', 'everyone'], example: 'me'),
            new OA\Property(property: 'title', type: 'string', example: 'Offer writer'),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'tryIt', type: 'array', items: new OA\Items(type: 'string')),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Assistant created, or the person already has it', content: new OA\JsonContent(
                required: ['success', 'agentId', 'alreadyInstalled', 'shared', 'sharingOff', 'waitingForReview'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'agentId', type: 'integer', example: 12),
                    new OA\Property(property: 'alreadyInstalled', type: 'boolean', example: false),
                    new OA\Property(property: 'shared', type: 'boolean', example: false),
                    new OA\Property(property: 'sharingOff', type: 'boolean', example: false),
                    new OA\Property(property: 'waitingForReview', type: 'boolean', example: false),
                ],
            )),
            new OA\Response(response: 400, description: 'The request body is not usable'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 403, description: 'Only an administrator can install for everyone'),
            new OA\Response(response: 404, description: 'Unknown template, or assistants are turned off'),
        ],
    )]
    public function install(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->run(function () use ($user, $id, $request): JsonResponse {
            $result = $this->installer->install($user, $id, AgentTemplateInstallRequest::fromBody($request->getContent()));

            return $this->json($this->resultBody($result->agentId, $result->alreadyInstalled, $result->shared, $result->sharingOff, $result->waitingForReview));
        });
    }

    #[Route('/{id}/diff', name: 'diff', methods: ['GET'], requirements: ['id' => '[a-z0-9][a-z0-9-]{0,31}'])]
    #[OA\Get(
        path: '/api/v1/agent-templates/{id}/diff',
        summary: 'Show what a template update would change',
        description: 'Compares the assistant instructions the person has now with the template. The name is never part of the change.',
        tags: ['Agent templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Instruction diff', content: new OA\JsonContent(
                required: ['success', 'from', 'to', 'keepsName'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'from', type: 'string'),
                    new OA\Property(property: 'to', type: 'string'),
                    new OA\Property(property: 'keepsName', type: 'boolean', example: true),
                ],
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No installed copy, or assistants are turned off'),
        ],
    )]
    public function diff(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->run(function () use ($user, $id): JsonResponse {
            $diff = $this->installer->diff($user, $id);

            return $this->json(['success' => true, 'from' => $diff['from'], 'to' => $diff['to'], 'keepsName' => true]);
        });
    }

    #[Route('/{id}/update', name: 'update', methods: ['POST'], requirements: ['id' => '[a-z0-9][a-z0-9-]{0,31}'])]
    #[OA\Post(
        path: '/api/v1/agent-templates/{id}/update',
        summary: 'Update an installed template without renaming it',
        description: 'Replaces the instructions and publishes them. The name, description, icon, chosen folder and chosen model stay.',
        tags: ['Agent templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(
                required: ['success', 'agentId', 'alreadyInstalled', 'shared', 'sharingOff', 'waitingForReview'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'agentId', type: 'integer', example: 12),
                    new OA\Property(property: 'alreadyInstalled', type: 'boolean', example: false),
                    new OA\Property(property: 'shared', type: 'boolean', example: false),
                    new OA\Property(property: 'sharingOff', type: 'boolean', example: false),
                    new OA\Property(property: 'waitingForReview', type: 'boolean', example: false),
                ],
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No installed copy, or assistants are turned off'),
        ],
    )]
    public function update(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->run(function () use ($user, $id): JsonResponse {
            $result = $this->installer->update($user, $id);

            return $this->json($this->resultBody($result->agentId, false, false, false, false));
        });
    }

    #[Route('/{id}/withdraw-review', name: 'withdraw_review', methods: ['POST'], requirements: ['id' => '[a-z0-9][a-z0-9-]{0,31}'])]
    #[OA\Post(
        path: '/api/v1/agent-templates/{id}/withdraw-review',
        summary: 'Take back a request to share an assistant with everyone',
        description: 'The assistant stays with the person who installed it. Nothing is shared.',
        tags: ['Agent templates'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The request was taken back', content: new OA\JsonContent(
                required: ['success'],
                properties: [new OA\Property(property: 'success', type: 'boolean', example: true)],
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'No waiting request, or assistants are turned off'),
        ],
    )]
    public function withdrawReview(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        return $this->run(function () use ($user, $id): JsonResponse {
            $this->installer->withdrawReview($user, $id);

            return $this->json(['success' => true]);
        });
    }

    /**
     * @return array{success: true, agentId: int, alreadyInstalled: bool, shared: bool, sharingOff: bool, waitingForReview: bool}
     */
    private function resultBody(int $agentId, bool $alreadyInstalled, bool $shared, bool $sharingOff, bool $waitingForReview): array
    {
        return [
            'success' => true,
            'agentId' => $agentId,
            'alreadyInstalled' => $alreadyInstalled,
            'shared' => $shared,
            'sharingOff' => $sharingOff,
            'waitingForReview' => $waitingForReview,
        ];
    }

    private function run(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (AgentTemplateNotFoundException) {
            return $this->json(['error' => 'That template is not on this server.'], Response::HTTP_NOT_FOUND);
        } catch (AgentTemplateForbiddenException) {
            return $this->json(['error' => 'Only an administrator can install an assistant for everyone.'], Response::HTTP_FORBIDDEN);
        } catch (AgentPublishReviewException $e) {
            $status = AgentPublishReviewException::FORBIDDEN === $e->reason ? Response::HTTP_FORBIDDEN : Response::HTTP_NOT_FOUND;

            return $this->json(['error' => $e->getMessage(), 'code' => $e->reason], $status);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->config->isEnabled((int) $user->getId())) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return null;
    }
}
