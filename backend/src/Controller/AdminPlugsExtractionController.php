<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Plug\Extraction\ExtractionAdminService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/plugs/extraction')]
#[OA\Tag(name: 'Admin Plugs Extraction')]
final class AdminPlugsExtractionController extends AbstractController
{
    public function __construct(
        private readonly ExtractionAdminService $extractionAdmin,
    ) {
    }

    #[Route('', name: 'admin_plugs_extraction_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/plugs/extraction',
        summary: 'List extraction adapters, chains and quality settings',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Extraction']
    )]
    #[OA\Response(
        response: 200,
        description: 'Extraction adapters, configured chains and quality gate',
        content: new OA\JsonContent(
            required: ['adapters', 'chains', 'quality'],
            properties: [
                new OA\Property(
                    property: 'adapters',
                    type: 'array',
                    items: new OA\Items(
                        required: ['key', 'label', 'docsUrl', 'sovereignty', 'health'],
                        properties: [
                            new OA\Property(property: 'key', type: 'string', example: 'docling'),
                            new OA\Property(property: 'label', type: 'string', example: 'Docling'),
                            new OA\Property(property: 'docsUrl', type: 'string', example: 'https://github.com/docling-project/docling-serve'),
                            new OA\Property(property: 'sovereignty', type: 'string', example: 'self-hosted'),
                            new OA\Property(
                                property: 'health',
                                required: ['available', 'reason'],
                                properties: [
                                    new OA\Property(property: 'available', type: 'boolean', example: false),
                                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                                ],
                                type: 'object'
                            ),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(
                    property: 'chains',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
                    example: ['document' => ['structured_office', 'office_convert', 'tika', 'pdf_vision']]
                ),
                new OA\Property(
                    property: 'quality',
                    required: ['minLength', 'minEntropy', 'applyTo'],
                    properties: [
                        new OA\Property(property: 'minLength', type: 'integer', example: 10),
                        new OA\Property(property: 'minEntropy', type: 'number', example: 3.0),
                        new OA\Property(property: 'applyTo', type: 'array', items: new OA\Items(type: 'string'), example: ['pdf']),
                    ],
                    type: 'object'
                ),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        return $this->json($this->extractionAdmin->status());
    }

    #[Route('/chains', name: 'admin_plugs_extraction_chains', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/admin/plugs/extraction/chains',
        summary: 'Replace extraction chain order for one or more families',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Extraction']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['chains'],
            properties: [
                new OA\Property(
                    property: 'chains',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
                    example: ['document' => ['docling', 'tika', 'pdf_vision']]
                ),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Updated extraction status',
        content: new OA\JsonContent(
            required: ['adapters', 'chains', 'quality'],
            properties: [
                new OA\Property(property: 'adapters', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'chains', type: 'object'),
                new OA\Property(property: 'quality', type: 'object'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 422, description: 'Unknown family or adapter key')]
    public function saveChains(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\is_array($data['chains'] ?? null)) {
            return $this->json(['error' => 'chains object is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->extractionAdmin->setChains($data['chains']));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/test', name: 'admin_plugs_extraction_test', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/plugs/extraction/test',
        summary: 'Probe every configured extra extractor then the built-in path',
        security: [['Bearer' => []]],
        tags: ['Admin Plugs Extraction']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file'],
                properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')],
                type: 'object'
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Winner, per-adapter attempts and a text preview',
        content: new OA\JsonContent(
            required: ['winner', 'strategy', 'attempts', 'preview', 'markdown'],
            properties: [
                new OA\Property(property: 'winner', type: 'string', nullable: true, example: 'tika'),
                new OA\Property(property: 'strategy', type: 'string', example: 'tika'),
                new OA\Property(
                    property: 'attempts',
                    type: 'array',
                    items: new OA\Items(
                        required: ['key', 'verdict', 'ms'],
                        properties: [
                            new OA\Property(property: 'key', type: 'string', example: 'docling'),
                            new OA\Property(property: 'verdict', type: 'string', example: 'unavailable'),
                            new OA\Property(property: 'ms', type: 'integer', example: 12),
                        ],
                        type: 'object'
                    )
                ),
                new OA\Property(property: 'preview', type: 'string'),
                new OA\Property(property: 'markdown', type: 'boolean', example: false),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 400, description: 'Missing file')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function test(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($resp = $this->requireAdmin($user)) {
            return $resp;
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->json(['error' => 'A file is required'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->extractionAdmin->testFile(
            $file->getPathname(),
            $file->getClientOriginalName() ?: $file->getFilename(),
        ));
    }

    private function requireAdmin(?User $user): ?JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
