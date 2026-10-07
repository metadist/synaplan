<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SavedPrompt;
use App\Entity\User;
use App\Repository\SavedPromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/saved-prompts', name: 'api_saved_prompts_')]
#[OA\Tag(name: 'Prompts')]
final class SavedPromptController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SavedPromptRepository $prompts,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/saved-prompts',
        summary: 'List saved prompts you own',
        tags: ['Prompts'],
        responses: [
            new OA\Response(response: 200, description: 'Saved prompts', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'prompts', type: 'array', items: new OA\Items(
                    required: ['id', 'name', 'command', 'body'],
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'command', type: 'string'),
                        new OA\Property(property: 'body', type: 'string'),
                        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'variables', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'updatedAt', type: 'string'),
                    ],
                    type: 'object',
                )),
            ])),
        ]
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $rows = [];
        foreach ($this->prompts->findByUser($user->getId()) as $prompt) {
            $rows[] = $prompt->toArray();
        }

        return $this->json(['success' => true, 'prompts' => $rows]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/saved-prompts',
        summary: 'Save a prompt',
        tags: ['Prompts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'command', 'body'], properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'command', type: 'string'),
            new OA\Property(property: 'body', type: 'string'),
            new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 400, description: 'Invalid prompt'),
        ]
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $payload = $this->payload($request);
        $error = $this->validate($payload, $user->getId(), null);
        if (null !== $error) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }
        $prompt = $this->fill(new SavedPrompt(), $payload, $user->getId());
        $this->em->persist($prompt);
        $this->em->flush();

        return $this->json(['success' => true, 'prompt' => $prompt->toArray()], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $prompt = $this->prompts->findOwned($id, $user->getId());
        if (!$prompt instanceof SavedPrompt) {
            return $this->json(['error' => 'Prompt not found'], Response::HTTP_NOT_FOUND);
        }
        $payload = $this->payload($request);
        $error = $this->validate($payload, $user->getId(), $prompt->getId());
        if (null !== $error) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }
        $this->fill($prompt, $payload, $user->getId());
        $this->em->flush();

        return $this->json(['success' => true, 'prompt' => $prompt->toArray()]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $prompt = $this->prompts->findOwned($id, $user->getId());
        if (!$prompt instanceof SavedPrompt) {
            return $this->json(['error' => 'Prompt not found'], Response::HTTP_NOT_FOUND);
        }
        $this->em->remove($prompt);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validate(array $payload, int $userId, ?int $ignoreId): ?string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ('' === $name || strlen($name) > 120) {
            return 'Give the prompt a name.';
        }
        $command = SavedPrompt::normalizeCommand((string) ($payload['command'] ?? ''));
        $commandError = SavedPrompt::commandError($command);
        if (null !== $commandError) {
            return $commandError;
        }
        $existing = $this->prompts->findByCommand($userId, $command);
        if ($existing instanceof SavedPrompt && $existing->getId() !== $ignoreId) {
            return 'You already have a prompt with that command.';
        }
        $body = trim((string) ($payload['body'] ?? ''));
        if ('' === $body) {
            return 'Write the prompt text.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fill(SavedPrompt $prompt, array $payload, int $userId): SavedPrompt
    {
        $tags = [];
        if (is_array($payload['tags'] ?? null)) {
            foreach ($payload['tags'] as $tag) {
                if (is_string($tag)) {
                    $tags[] = $tag;
                }
            }
        }
        $prompt->setUserId($userId);
        $prompt->setName((string) $payload['name']);
        $prompt->setCommand((string) $payload['command']);
        $prompt->setBody((string) $payload['body']);
        $prompt->setTags($tags);
        $prompt->touch();

        return $prompt;
    }
}
