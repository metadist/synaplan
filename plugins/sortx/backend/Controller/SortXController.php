<?php

declare(strict_types=1);

namespace Plugin\SortX\Controller;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\ModelConfigService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * SortX Plugin API Controller.
 *
 * Provides document classification endpoints for the SortX local tool.
 * Routes: /api/v1/user/{userId}/plugins/sortx/...
 */
#[Route('/api/v1/user/{userId}/plugins/sortx', name: 'api_plugin_sortx_')]
#[OA\Tag(name: 'SortX Plugin')]
class SortXController extends AbstractController
{
    private const CLASSIFICATION_PROMPT = <<<'PROMPT'
You are a document classification assistant. Analyze the document and assign ALL applicable categories.

IMPORTANT: A document can belong to MULTIPLE categories. For example, an employment contract belongs to both "contract" AND "human_resources".

Categories (assign ALL that apply):
- contract: Legal agreements, contracts, leases, terms of service, NDAs
- invoice: Bills, invoices, receipts, payment documents
- request: Formal requests, applications, proposals, inquiries
- research: Academic papers, research documents, studies, whitepapers, theses
- human_resources: CVs, resumes, job applications, employment documents, personnel files
- sales: Quotes, quotations, offers, orders, purchase documents

User Context:
%s

Document filename: %s

Respond ONLY with a JSON object (no markdown, no explanation):
{"categories": ["category1", "category2"], "confidence": 0.0-1.0, "reasoning": "brief explanation"}

Rules:
1. Return an ARRAY of categories - a document can match multiple categories
2. Return empty array [] if confidence would be below 0.5 for all categories
3. Consider filename as a hint but prioritize content
4. Be thorough - if a document fits multiple categories, include ALL of them
PROMPT;

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/classify', name: 'classify', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/sortx/classify',
        summary: 'Classify document text using AI',
        description: 'Analyzes extracted document text and returns classification with multiple categories',
        security: [['Bearer' => []]],
        tags: ['SortX Plugin']
    )]
    #[OA\Parameter(
        name: 'userId',
        in: 'path',
        required: true,
        description: 'User ID',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['text'],
            properties: [
                new OA\Property(property: 'filename', type: 'string', example: 'invoice_2024.pdf'),
                new OA\Property(property: 'text', type: 'string', example: 'Invoice #12345...'),
                new OA\Property(
                    property: 'user_context',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                        new OA\Property(property: 'address', type: 'string', example: '123 Main St'),
                        new OA\Property(property: 'aka_names', type: 'array', items: new OA\Items(type: 'string')),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Classification result',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'string'), example: '["contract", "human_resources"]'),
                        new OA\Property(property: 'confidence', type: 'number', example: 0.92),
                        new OA\Property(property: 'reasoning', type: 'string'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function classify(
        Request $request,
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || $user->getId() !== $userId) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (empty($data['text'])) {
            return $this->json(['success' => false, 'error' => 'Text is required'], Response::HTTP_BAD_REQUEST);
        }

        $filename = $data['filename'] ?? 'unknown';
        $text = $data['text'];
        $userContext = $data['user_context'] ?? [];

        // Truncate text if too long
        $maxLength = 10000;
        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength).'... [truncated]';
        }

        // Build user context string
        $contextStr = 'None provided';
        if (!empty($userContext)) {
            $parts = [];
            if (!empty($userContext['name'])) {
                $parts[] = 'Name: '.$userContext['name'];
            }
            if (!empty($userContext['address'])) {
                $parts[] = 'Address: '.$userContext['address'];
            }
            if (!empty($userContext['aka_names'])) {
                $parts[] = 'Also known as: '.implode(', ', $userContext['aka_names']);
            }
            if (!empty($parts)) {
                $contextStr = implode("\n", $parts);
            }
        }

        try {
            $systemPrompt = sprintf(self::CLASSIFICATION_PROMPT, $contextStr, $filename);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Document content:\n\n".$text],
            ];

            // Get user's model config
            $provider = null;
            $modelName = null;
            $modelId = $this->modelConfigService->getDefaultModel('CHAT', $userId);
            if ($modelId) {
                $provider = $this->modelConfigService->getProviderForModel($modelId);
                $modelName = $this->modelConfigService->getModelName($modelId);
            }

            $response = $this->aiFacade->chat($messages, $userId, [
                'provider' => $provider,
                'model' => $modelName,
                'temperature' => 0.1,
                'max_tokens' => 500,
            ]);

            $result = $this->parseClassificationResponse($response['content']);

            $this->logger->info('SortX classification completed', [
                'user_id' => $userId,
                'filename' => $filename,
                'categories' => $result['categories'],
                'confidence' => $result['confidence'],
            ]);

            return $this->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('SortX classification failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Classification failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/analyze-file', name: 'analyze_file', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/sortx/analyze-file',
        summary: 'Full file analysis with vision models',
        description: 'Uploads and analyzes a file using vision models for image-based documents',
        security: [['Bearer' => []]],
        tags: ['SortX Plugin']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary'),
                    new OA\Property(property: 'user_context', type: 'string', description: 'JSON-encoded user context'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Analysis result',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'confidence', type: 'number'),
                        new OA\Property(property: 'extracted_text', type: 'string'),
                    ]
                ),
            ]
        )
    )]
    public function analyzeFile(
        Request $request,
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user || $user->getId() !== $userId) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file) {
            return $this->json(['success' => false, 'error' => 'File is required'], Response::HTTP_BAD_REQUEST);
        }

        // Check file size (50MB default)
        $maxSize = 50 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return $this->json(['success' => false, 'error' => 'File too large'], Response::HTTP_BAD_REQUEST);
        }

        $userContext = [];
        if ($request->request->has('user_context')) {
            $userContext = json_decode($request->request->get('user_context'), true) ?? [];
        }

        try {
            $filename = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();

            $this->logger->info('SortX file analysis requested', [
                'user_id' => $userId,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size' => $file->getSize(),
            ]);

            // TODO: Implement actual vision model call
            // This would involve:
            // 1. Converting file to base64
            // 2. Calling vision-capable model
            // 3. Parsing response

            return $this->json([
                'success' => true,
                'data' => [
                    'categories' => [],
                    'confidence' => 0.0,
                    'extracted_text' => null,
                    'metadata' => [
                        'filename' => $filename,
                        'mime_type' => $mimeType,
                        'size' => $file->getSize(),
                    ],
                    'note' => 'Vision analysis not yet implemented - use /classify with extracted text',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('SortX file analysis failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'File analysis failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/categories', name: 'categories', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/sortx/categories',
        summary: 'Get available classification categories',
        security: [['Bearer' => []]],
        tags: ['SortX Plugin']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of categories',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(
                    property: 'categories',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'key', type: 'string'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'description', type: 'string'),
                        ]
                    )
                ),
            ]
        )
    )]
    public function categories(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || $user->getId() !== $userId) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'categories' => [
                [
                    'key' => 'contract',
                    'name' => 'Contract',
                    'description' => 'Legal agreements, contracts, leases, NDAs',
                ],
                [
                    'key' => 'invoice',
                    'name' => 'Invoice',
                    'description' => 'Bills, invoices, receipts, payment documents',
                ],
                [
                    'key' => 'request',
                    'name' => 'Request',
                    'description' => 'Formal requests, applications, proposals',
                ],
                [
                    'key' => 'research',
                    'name' => 'Research',
                    'description' => 'Academic papers, studies, whitepapers, theses',
                ],
                [
                    'key' => 'human_resources',
                    'name' => 'Human Resources',
                    'description' => 'CVs, resumes, job applications, personnel files',
                ],
                [
                    'key' => 'sales',
                    'name' => 'Sales',
                    'description' => 'Quotes, quotations, offers, orders',
                ],
            ],
            'note' => 'Documents can belong to multiple categories. Empty array means unknown/unclassified.',
        ]);
    }

    /**
     * Parse AI response into structured classification result.
     * Supports multiple categories (1-to-n relationship).
     */
    private function parseClassificationResponse(string $response): array
    {
        // Try to extract JSON from response
        $response = trim($response);

        // Remove markdown code blocks if present
        if (str_starts_with($response, '```')) {
            $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
            $response = preg_replace('/\s*```$/', '', $response);
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Fallback if not valid JSON
            return [
                'categories' => [],
                'confidence' => 0.0,
                'reasoning' => 'Failed to parse AI response',
            ];
        }

        // Handle both old single-category and new multi-category format
        $categories = [];
        if (isset($decoded['categories']) && is_array($decoded['categories'])) {
            $categories = $decoded['categories'];
        } elseif (isset($decoded['category']) && $decoded['category'] !== 'unknown') {
            // Backward compatibility with single category
            $categories = [$decoded['category']];
        }

        return [
            'categories' => $categories,
            'confidence' => (float) ($decoded['confidence'] ?? 0.0),
            'reasoning' => $decoded['reasoning'] ?? null,
        ];
    }
}
