<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Mcp\Notion\NotionActionException;
use App\Service\Mcp\Notion\NotionActivityLog;
use App\Service\Mcp\Notion\NotionAppUnavailableException;
use App\Service\Mcp\Notion\NotionConnectionService;
use App\Service\Mcp\Notion\NotionFilePush;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * What Synaplan changed in Notion, and adding a library file to a page.
 */
#[Route('/api/v1/apps/notion', name: 'api_apps_notion_content_')]
#[OA\Tag(name: 'Notion')]
final class NotionContentController extends AbstractController
{
    public function __construct(
        private readonly NotionConnectionService $notion,
        private readonly NotionActivityLog $activity,
        private readonly NotionFilePush $files,
    ) {
    }

    #[Route('/activity', name: 'activity', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/apps/notion/activity',
        summary: 'Notion pages Synaplan recently created or changed',
        description: 'Newest first, at most 10. Covers chat, saved tasks and files added to a page.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Recent changes',
                content: new OA\JsonContent(
                    required: ['success', 'items'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'items',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'action', 'title', 'url', 'created_at'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 4),
                                    new OA\Property(property: 'action', type: 'string', enum: ['created', 'updated', 'file'], example: 'created'),
                                    new OA\Property(property: 'title', type: 'string', example: 'Weekly notes'),
                                    new OA\Property(property: 'url', type: 'string', nullable: true, example: 'https://www.notion.so/0123456789abcdef0123456789abcdef'),
                                    new OA\Property(property: 'created_at', type: 'integer', example: 1793091600),
                                ],
                                type: 'object'
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off'),
        ]
    )]
    public function activity(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->notion->assertAvailable($user->getId());
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'items' => $this->activity->recent($user->getId())]);
    }

    #[Route('/files', name: 'files', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/apps/notion/files',
        summary: 'Add a library file to a Notion page',
        description: 'Uploads the file to Notion and adds it at the end of the page. The file stays in Synaplan. Needs your own Notion connection; a shared one cannot write.',
        security: [['Bearer' => []]],
        tags: ['Notion'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['file_id', 'page'],
                properties: [
                    new OA\Property(property: 'file_id', type: 'integer', example: 42),
                    new OA\Property(property: 'page', type: 'string', description: 'Link or id of the Notion page', example: 'https://www.notion.so/Weekly-0123456789abcdef0123456789abcdef'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'File added to the page',
                content: new OA\JsonContent(
                    required: ['success', 'page_url', 'page_title', 'file_name'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'page_url', type: 'string', example: 'https://www.notion.so/0123456789abcdef0123456789abcdef'),
                        new OA\Property(property: 'page_title', type: 'string', example: 'Weekly notes'),
                        new OA\Property(property: 'file_name', type: 'string', example: 'report.pdf'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Not added; the error says why and that nothing changed in Notion'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Notion is turned off'),
        ]
    )]
    public function pushFile(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_int($payload['file_id'] ?? null) || !is_string($payload['page'] ?? null)) {
            return $this->json(['success' => false, 'error' => 'Choose a file and paste the link of a Notion page. Nothing was added to Notion.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->notion->assertAvailable($user->getId());

            return $this->json(['success' => true, ...$this->files->push($user->getId(), $payload['file_id'], $payload['page'])]);
        } catch (NotionAppUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (NotionActionException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
