<?php

declare(strict_types=1);

namespace Plugin\SortX\Controller;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\File\FileProcessor;
use App\Service\ModelConfigService;
use App\Service\PluginDataService;
use OpenApi\Attributes as OA;
use Plugin\SortX\Service\PromptGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * SortX Plugin API Controller v3.0.
 *
 * Provides document classification endpoints with metadata extraction.
 * Uses PluginDataService for non-invasive data storage (no plugin-specific tables).
 *
 * Routes: /api/v1/user/{userId}/plugins/sortx/...
 */
#[Route('/api/v1/user/{userId}/plugins/sortx', name: 'api_plugin_sortx_')]
#[OA\Tag(name: 'SortX Plugin')]
class SortXController extends AbstractController
{
    private const PLUGIN_NAME = 'sortx';
    private const DATA_TYPE_CATEGORY = 'category';

    public function __construct(
        private AiFacade $aiFacade,
        private FileProcessor $fileProcessor,
        private ModelConfigService $modelConfigService,
        private PromptGenerator $promptGenerator,
        private PluginDataService $pluginData,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    /**
     * Get the complete category schema for the local tool.
     */
    #[Route('/schema', name: 'schema', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/sortx/schema',
        summary: 'Get category schema and classification prompt',
        description: 'Returns the user\'s category definitions, fields, and generated classification prompt',
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
    #[OA\Response(
        response: 200,
        description: 'Schema retrieved successfully',
        content: new OA\JsonContent(
            required: ['success', 'data'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'categories',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'key', type: 'string', example: 'invoice'),
                                    new OA\Property(property: 'name', type: 'string', example: 'Invoice'),
                                    new OA\Property(property: 'description', type: 'string'),
                                    new OA\Property(
                                        property: 'fields',
                                        type: 'array',
                                        items: new OA\Items(
                                            type: 'object',
                                            properties: [
                                                new OA\Property(property: 'key', type: 'string'),
                                                new OA\Property(property: 'name', type: 'string'),
                                                new OA\Property(property: 'type', type: 'string'),
                                                new OA\Property(property: 'required', type: 'boolean'),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                        new OA\Property(property: 'prompt_preview', type: 'string'),
                        new OA\Property(
                            property: 'config',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'confidence_threshold', type: 'number', example: 0.5),
                                new OA\Property(property: 'max_text_length', type: 'integer', example: 10000),
                                new OA\Property(property: 'max_file_size_mb', type: 'integer', example: 50),
                            ]
                        ),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getSchema(
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $schema = $this->promptGenerator->getSchemaForUser($userId);

        // Generate prompt with metadata extraction enabled
        $promptPreview = $this->promptGenerator->generatePrompt($schema, extractMetadata: true);

        // Get config (could be extended to read from BCONFIG)
        $config = $this->getPluginConfig($userId);

        return $this->json([
            'success' => true,
            'data' => [
                'categories' => $schema,
                'prompt_preview' => $promptPreview,
                'config' => $config,
            ],
        ]);
    }

    /**
     * Get classification categories.
     */
    #[Route('/categories', name: 'categories', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/sortx/categories',
        summary: 'Get available classification categories',
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
                            new OA\Property(property: 'fields', type: 'array', items: new OA\Items(type: 'object')),
                        ]
                    )
                ),
            ]
        )
    )]
    public function categories(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $schema = $this->promptGenerator->getSchemaForUser($userId);

        return $this->json([
            'success' => true,
            'categories' => $schema,
        ]);
    }

    /**
     * Classify document text and optionally extract metadata.
     */
    #[Route('/classify', name: 'classify', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/sortx/classify',
        summary: 'Classify document text with optional metadata extraction',
        description: 'Analyzes extracted document text, returns multi-label classification and structured metadata',
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
                new OA\Property(property: 'text', type: 'string', example: 'Invoice #12345 from Acme Corp...'),
                new OA\Property(property: 'extract_metadata', type: 'boolean', example: true, description: 'Whether to extract structured metadata'),
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
                    property: 'categories',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    example: '["invoice", "contract"]'
                ),
                new OA\Property(property: 'confidence', type: 'number', example: 0.92),
                new OA\Property(property: 'reasoning', type: 'string'),
                new OA\Property(
                    property: 'metadata',
                    type: 'object',
                    nullable: true,
                    example: '{"sender": {"value": "Acme Corp", "confidence": 0.95}, "amount": {"value": 1250.00, "confidence": 0.98}}'
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
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (empty($data['text'])) {
            return $this->json(['success' => false, 'error' => 'Text is required'], Response::HTTP_BAD_REQUEST);
        }

        $filename = $data['filename'] ?? 'document';
        $text = $data['text'];
        $extractMetadata = $data['extract_metadata'] ?? false;

        // Get config
        $config = $this->getPluginConfig($userId);
        $maxLength = $config['max_text_length'];

        // Truncate text if too long
        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength).'... [truncated]';
        }

        // Build prompt from user's schema
        $schema = $this->promptGenerator->getSchemaForUser($userId);
        if (empty($schema)) {
            return $this->json([
                'success' => false,
                'error' => 'No categories configured. Please run plugin installation to seed default categories.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $systemPrompt = $this->promptGenerator->generatePrompt($schema, $extractMetadata);
        $userMessage = "Classify this document:\n\nFilename: {$filename}\n\nContent:\n{$text}";

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
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
                'max_tokens' => 1000,
            ]);

            $result = $this->parseAiResponse($response['content']);

            $this->logger->info('SortX classification completed', [
                'user_id' => $userId,
                'filename' => $filename,
                'categories' => $result['categories'],
                'confidence' => $result['confidence'],
                'has_metadata' => isset($result['metadata']),
            ]);

            return $this->json([
                'success' => true,
                'categories' => $result['categories'] ?? ['unknown'],
                'confidence' => $result['confidence'] ?? 0.0,
                'reasoning' => $result['reasoning'] ?? null,
                'metadata' => $extractMetadata ? ($result['metadata'] ?? null) : null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('SortX classification failed', [
                'user_id' => $userId,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Classification failed: '.$e->getMessage(),
                'categories' => ['unknown'],
                'confidence' => 0.0,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Full file analysis with vision models.
     */
    #[Route('/analyze-file', name: 'analyze_file', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/sortx/analyze-file',
        summary: 'Full file analysis with text extraction and classification',
        description: 'Uploads and analyzes a file, extracting text and classifying with optional metadata',
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
                    new OA\Property(property: 'extract_metadata', type: 'boolean', description: 'Whether to extract metadata'),
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
                new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'confidence', type: 'number'),
                new OA\Property(property: 'reasoning', type: 'string'),
                new OA\Property(property: 'metadata', type: 'object', nullable: true),
                new OA\Property(property: 'extracted_text', type: 'string', nullable: true),
            ]
        )
    )]
    public function analyzeFile(
        Request $request,
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file) {
            return $this->json(['success' => false, 'error' => 'File is required'], Response::HTTP_BAD_REQUEST);
        }

        $config = $this->getPluginConfig($userId);
        $maxSize = $config['max_file_size_mb'] * 1024 * 1024;

        if ($file->getSize() > $maxSize) {
            return $this->json([
                'success' => false,
                'error' => "File too large. Maximum size is {$config['max_file_size_mb']}MB",
            ], Response::HTTP_BAD_REQUEST);
        }

        $extractMetadata = filter_var($request->request->get('extract_metadata', 'false'), FILTER_VALIDATE_BOOLEAN);

        try {
            $filename = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();

            $this->logger->info('SortX file analysis requested', [
                'user_id' => $userId,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size' => $file->getSize(),
            ]);

            // TODO: Implement actual file processing using Synaplan's FileProcessor
            // This would involve:
            // 1. Extract text using FileProcessor->extractText()
            // 2. If extraction fails, use vision model via AiFacade->analyzeImage()
            // 3. Classify extracted text

            return $this->json([
                'success' => true,
                'categories' => [],
                'confidence' => 0.0,
                'reasoning' => null,
                'metadata' => null,
                'extracted_text' => null,
                'file_info' => [
                    'filename' => $filename,
                    'mime_type' => $mimeType,
                    'size' => $file->getSize(),
                ],
                'note' => 'Full file analysis implementation pending - use /classify with pre-extracted text',
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

    /**
     * Extract text from uploaded file using FileProcessor (v3.0).
     *
     * Uses Synaplan's robust extraction pipeline:
     * 1. Native (plain text)
     * 2. Tika (PDF, DOCX, etc.)
     * 3. Rasterize + Vision AI (fallback for scanned PDFs)
     * 4. Vision AI (images)
     *
     * This endpoint counts against user's API usage quota.
     */
    #[Route('/extract-text', name: 'extract_text', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/sortx/extract-text',
        summary: 'Extract text from a document file',
        description: 'Uses Synaplan\'s FileProcessor for robust text extraction with Tika and Vision AI fallback',
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
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file'],
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Document file to extract text from'),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Text extraction result',
        content: new OA\JsonContent(
            required: ['success'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'text', type: 'string', description: 'Extracted text content'),
                new OA\Property(property: 'strategy', type: 'string', example: 'tika', description: 'Extraction method used'),
                new OA\Property(property: 'bytes', type: 'integer', example: 5432, description: 'Length of extracted text'),
                new OA\Property(property: 'mime', type: 'string', example: 'application/pdf'),
                new OA\Property(property: 'filename', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request (missing file, too large)')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function extractText(
        Request $request,
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file) {
            return $this->json(['success' => false, 'error' => 'File is required'], Response::HTTP_BAD_REQUEST);
        }

        $config = $this->getPluginConfig($userId);
        $maxSize = $config['max_file_size_mb'] * 1024 * 1024;

        if ($file->getSize() > $maxSize) {
            return $this->json([
                'success' => false,
                'error' => "File too large. Maximum size is {$config['max_file_size_mb']}MB",
            ], Response::HTTP_BAD_REQUEST);
        }

        $filename = $file->getClientOriginalName();
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        $this->logger->info('SortX text extraction requested', [
            'user_id' => $userId,
            'filename' => $filename,
            'size' => $file->getSize(),
        ]);

        try {
            // Move uploaded file to temp location for processing
            $tempDir = $this->uploadDir.'/sortx_temp';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempFilename = uniqid('sortx_').'_'.preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
            $tempPath = $tempDir.'/'.$tempFilename;
            $file->move($tempDir, $tempFilename);

            // Use FileProcessor for extraction (includes Tika + Vision fallback)
            $relativePath = 'sortx_temp/'.$tempFilename;

            try {
                [$extractedText, $meta] = $this->fileProcessor->extractText($relativePath, $extension, $userId);
            } finally {
                // Clean up temp file
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }

            $strategy = $meta['strategy'] ?? 'unknown';
            $bytes = strlen($extractedText);

            $this->logger->info('SortX text extraction completed', [
                'user_id' => $userId,
                'filename' => $filename,
                'strategy' => $strategy,
                'bytes' => $bytes,
            ]);

            // Truncate text if too long (prevent huge responses)
            $maxTextLength = $config['max_text_length'];
            if ($bytes > $maxTextLength) {
                $extractedText = mb_substr($extractedText, 0, $maxTextLength).'... [truncated]';
            }

            return $this->json([
                'success' => true,
                'text' => $extractedText,
                'strategy' => $strategy,
                'bytes' => $bytes,
                'mime' => $meta['mime'] ?? null,
                'filename' => $filename,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('SortX text extraction failed', [
                'user_id' => $userId,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Text extraction failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Verify user has access to this plugin instance.
     */
    private function canAccessPlugin(?User $user, int $userId): bool
    {
        if ($user === null) {
            return false;
        }

        // User can access their own plugin
        return $user->getId() === $userId;
    }

    /**
     * Get plugin configuration.
     *
     * @return array{confidence_threshold: float, max_text_length: int, max_file_size_mb: int}
     */
    private function getPluginConfig(int $userId): array
    {
        // TODO: Read from BCONFIG (P_sortx group) if configured
        return [
            'confidence_threshold' => 0.5,
            'max_text_length' => 10000,
            'max_file_size_mb' => 50,
        ];
    }

    /**
     * Parse AI response, handling common issues like markdown wrapping.
     *
     * @return array{categories: array, confidence: float, reasoning: ?string, metadata: ?array}
     */
    private function parseAiResponse(string $response): array
    {
        // Strip markdown code blocks if present
        $response = trim($response);
        if (str_starts_with($response, '```')) {
            $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
            $response = preg_replace('/\s*```$/', '', $response);
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Failed to parse AI response as JSON', [
                'response' => substr($response, 0, 500),
                'error' => json_last_error_msg(),
            ]);

            return [
                'categories' => ['unknown'],
                'confidence' => 0.0,
                'reasoning' => 'Failed to parse AI response',
                'metadata' => null,
            ];
        }

        return [
            'categories' => $decoded['categories'] ?? ['unknown'],
            'confidence' => (float) ($decoded['confidence'] ?? 0.0),
            'reasoning' => $decoded['reasoning'] ?? null,
            'metadata' => $decoded['metadata'] ?? null,
        ];
    }
}
