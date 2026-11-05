<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Literacy\AiLiteracy;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Optional confirmation that a person has read "Using AI well".
 * The card never blocks work.
 */
#[OA\Tag(name: 'AI literacy')]
final class AiLiteracyController extends AbstractController
{
    public function __construct(private readonly AiLiteracy $literacy)
    {
    }

    #[Route('/api/v1/me/ai-literacy', name: 'api_ai_literacy_me', methods: ['GET'])]
    #[OA\Get(path: '/api/v1/me/ai-literacy', summary: 'Whether the signed-in person should see the reading card', security: [['Bearer' => []]], tags: ['AI literacy'])]
    #[OA\Response(
        response: 200,
        description: 'Card state. due is false when the switch is off, already confirmed, or snoozed.',
        content: new OA\JsonContent(
            required: ['success', 'required', 'version', 'due'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'required', type: 'boolean', example: false),
                new OA\Property(property: 'version', type: 'string', example: '2026-11'),
                new OA\Property(property: 'due', type: 'boolean', example: false),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['success' => true] + $this->literacy->statusFor($user));
    }

    #[Route('/api/v1/me/ai-literacy/confirm', name: 'api_ai_literacy_confirm', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/me/ai-literacy/confirm', summary: 'Confirm the current version of Using AI well', security: [['Bearer' => []]], tags: ['AI literacy'])]
    #[OA\Response(response: 200, description: 'Recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true)]))]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function confirm(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        $this->literacy->confirm($user);

        return $this->json(['success' => true]);
    }

    #[Route('/api/v1/me/ai-literacy/remind', name: 'api_ai_literacy_remind', methods: ['POST'])]
    #[OA\Post(path: '/api/v1/me/ai-literacy/remind', summary: 'Hide the reading card until tomorrow', security: [['Bearer' => []]], tags: ['AI literacy'])]
    #[OA\Response(response: 200, description: 'Snoozed', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true)]))]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function remind(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        $this->literacy->remindTomorrow($user);

        return $this->json(['success' => true]);
    }

    #[Route('/api/v1/admin/ai-literacy', name: 'api_admin_ai_literacy_get', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(path: '/api/v1/admin/ai-literacy', summary: 'Reading switch and confirmation counts', security: [['Bearer' => []]], tags: ['AI literacy'])]
    #[OA\Parameter(name: 'ids', in: 'query', required: false, description: 'Comma-separated user ids to mark confirmed or not', schema: new OA\Schema(type: 'string', example: '1,2,3'))]
    #[OA\Response(
        response: 200,
        description: 'Switch, version, and how many people confirmed',
        content: new OA\JsonContent(
            required: ['success', 'required', 'version', 'confirmed', 'total', 'users'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'required', type: 'boolean', example: false),
                new OA\Property(property: 'version', type: 'string', example: '2026-11'),
                new OA\Property(property: 'confirmed', type: 'integer', example: 1),
                new OA\Property(property: 'total', type: 'integer', example: 3),
                new OA\Property(property: 'users', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'boolean')),
            ]
        )
    )]
    public function adminGet(Request $request): JsonResponse
    {
        $ids = $this->ids($request);

        return $this->json([
            'success' => true,
            'required' => $this->literacy->isRequired(),
            'version' => $this->literacy->version(),
            'confirmed' => $this->literacy->countConfirmed(),
            'total' => $this->literacy->countPeople(),
            'users' => (object) $this->literacy->confirmedMap($ids),
        ]);
    }

    #[Route('/api/v1/admin/ai-literacy', name: 'api_admin_ai_literacy_put', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Put(path: '/api/v1/admin/ai-literacy', summary: 'Ask people to read Using AI well', security: [['Bearer' => []]], tags: ['AI literacy'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['required'], properties: [new OA\Property(property: 'required', type: 'boolean', example: true)]))]
    #[OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'required', type: 'boolean', example: true)]))]
    public function adminPut(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $required = is_array($payload) && filter_var($payload['required'] ?? false, \FILTER_VALIDATE_BOOL);
        $this->literacy->setRequired($required);

        return $this->json(['success' => true, 'required' => $this->literacy->isRequired()]);
    }

    #[Route('/api/v1/admin/ai-literacy/export', name: 'api_admin_ai_literacy_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(path: '/api/v1/admin/ai-literacy/export', summary: 'CSV of who confirmed Using AI well', security: [['Bearer' => []]], tags: ['AI literacy'])]
    #[OA\Response(response: 200, description: 'CSV file')]
    public function export(): Response
    {
        $lines = ['id,email,version,confirmed'];
        $version = $this->literacy->version();
        foreach ($this->literacy->exportRows() as $row) {
            $email = str_replace('"', '""', $row['email']);
            $lines[] = $row['id'].',"'.$email.'",'.$version.','.$row['confirmed'];
        }

        return new Response(implode("\n", $lines)."\n", Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="ai-literacy-'.$version.'.csv"',
        ]);
    }

    /**
     * @return list<int>
     */
    private function ids(Request $request): array
    {
        $raw = (string) $request->query->get('ids', '');
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
