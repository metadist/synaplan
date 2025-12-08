<?php

namespace App\Controller;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/summary', name: 'api_summary_')]
#[OA\Tag(name: 'Summary')]
class SummaryController extends AbstractController
{
    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/summary/generate',
        summary: 'Generate document summary using AI',
        description: 'Generates a summary of the provided document text based on configuration',
        security: [['Bearer' => []]],
        tags: ['Summary']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['text'],
            properties: [
                new OA\Property(
                    property: 'text',
                    type: 'string',
                    description: 'Document text to summarize',
                    example: 'This is a long document that needs to be summarized...'
                ),
                new OA\Property(
                    property: 'summaryType',
                    type: 'string',
                    enum: ['abstractive', 'extractive', 'bullet-points'],
                    example: 'abstractive',
                    description: 'Type of summary to generate'
                ),
                new OA\Property(
                    property: 'length',
                    type: 'string',
                    enum: ['short', 'medium', 'long', 'custom'],
                    example: 'medium',
                    description: 'Target length of the summary'
                ),
                new OA\Property(
                    property: 'customLength',
                    type: 'integer',
                    example: 300,
                    description: 'Custom word count (only when length is "custom")',
                    nullable: true
                ),
                new OA\Property(
                    property: 'outputLanguage',
                    type: 'string',
                    example: 'en',
                    description: 'Output language code (en, de, fr, es)',
                    default: 'en'
                ),
                new OA\Property(
                    property: 'focusAreas',
                    type: 'array',
                    items: new OA\Items(
                        type: 'string',
                        enum: ['main-ideas', 'key-facts', 'conclusions', 'action-items', 'numbers-dates']
                    ),
                    example: ['main-ideas', 'key-facts'],
                    description: 'Areas to emphasize in the summary'
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Summary generated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'summary', type: 'string', example: 'This is the generated summary...'),
                new OA\Property(
                    property: 'metadata',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'original_length', type: 'integer', example: 5000),
                        new OA\Property(property: 'summary_length', type: 'integer', example: 250),
                        new OA\Property(property: 'compression_ratio', type: 'number', format: 'float', example: 0.05),
                        new OA\Property(property: 'processing_time_ms', type: 'integer', example: 1500),
                        new OA\Property(property: 'model', type: 'string', example: 'gpt-4'),
                        new OA\Property(property: 'provider', type: 'string', example: 'openai'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 400, description: 'Invalid request parameters')]
    #[OA\Response(response: 500, description: 'Summary generation failed')]
    public function generate(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $startTime = microtime(true);
        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (!isset($data['text']) || empty(trim($data['text']))) {
            return $this->json([
                'success' => false,
                'error' => 'Document text is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Extract configuration
        $text = trim($data['text']);
        $summaryType = $data['summaryType'] ?? 'abstractive';
        $length = $data['length'] ?? 'medium';
        $customLength = $data['customLength'] ?? null;
        $outputLanguage = $data['outputLanguage'] ?? 'en';
        $focusAreas = $data['focusAreas'] ?? ['main-ideas', 'key-facts'];

        // Validate summary type
        $validTypes = ['abstractive', 'extractive', 'bullet-points'];
        if (!in_array($summaryType, $validTypes, true)) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid summary type. Must be: '.implode(', ', $validTypes),
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate length
        $validLengths = ['short', 'medium', 'long', 'custom'];
        if (!in_array($length, $validLengths, true)) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid length. Must be: '.implode(', ', $validLengths),
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get word count for length specification
        $targetWordCount = match ($length) {
            'short' => '50-150',
            'medium' => '200-500',
            'long' => '500-1000',
            'custom' => (string) ($customLength ?? 300),
            default => '200-500',
        };

        // Get user's default chat model configuration
        $provider = null;
        $modelName = null;
        $modelId = null;

        try {
            $modelId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
            if ($modelId) {
                $provider = $this->modelConfigService->getProviderForModel($modelId);
                $modelName = $this->modelConfigService->getModelName($modelId);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not get default chat model, will use provider default', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('Summary generation request', [
            'user_id' => $user->getId(),
            'text_length' => strlen($text),
            'summary_type' => $summaryType,
            'length' => $length,
            'output_language' => $outputLanguage,
            'focus_areas' => implode(', ', $focusAreas),
            'model_id' => $modelId,
            'provider' => $provider,
            'model' => $modelName,
        ]);

        try {
            // Load the docsummary system prompt
            $systemPrompt = $this->promptRepository->findOneBy([
                'topic' => 'docsummary',
                'language' => 'en',
                'ownerId' => 0,
            ]);

            if (!$systemPrompt) {
                $this->logger->error('Doc summary prompt not found in database');

                return $this->json([
                    'success' => false,
                    'error' => 'Summary service not configured. Please load fixtures.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // Build the user prompt with configuration and document
            $configText = "Configuration:\n";
            $configText .= "- Summary Type: {$summaryType}\n";
            $configText .= "- Target Length: {$targetWordCount} words\n";
            $configText .= "- Output Language: {$outputLanguage}\n";
            $configText .= '- Focus Areas: '.implode(', ', $focusAreas)."\n\n";
            $configText .= "Document to summarize:\n\n{$text}";

            // Build messages array for AI
            $messages = [
                [
                    'role' => 'system',
                    'content' => $systemPrompt->getPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => $configText,
                ],
            ];

            // Build AI options
            $aiOptions = [
                'temperature' => 0.5, // Lower temperature for more consistent summaries
            ];

            // Add model configuration if available
            if ($provider) {
                $aiOptions['provider'] = $provider;
            }
            if ($modelName) {
                $aiOptions['model'] = $modelName;
            }

            // Call AI with streaming disabled (we want full response)
            $response = $this->aiFacade->chat(
                $messages,
                $user->getId(),
                $aiOptions
            );

            $summary = trim($response['content']);
            $processingTime = (int) ((microtime(true) - $startTime) * 1000);

            // Calculate statistics
            $originalWordCount = str_word_count($text);
            $summaryWordCount = str_word_count($summary);
            $compressionRatio = $originalWordCount > 0
                ? round($summaryWordCount / $originalWordCount, 3)
                : 0;

            $this->logger->info('Summary generated successfully', [
                'user_id' => $user->getId(),
                'original_words' => $originalWordCount,
                'summary_words' => $summaryWordCount,
                'compression_ratio' => $compressionRatio,
                'processing_time_ms' => $processingTime,
                'provider' => $response['provider'],
                'model' => $response['model'],
            ]);

            return $this->json([
                'success' => true,
                'summary' => $summary,
                'metadata' => [
                    'original_length' => $originalWordCount,
                    'summary_length' => $summaryWordCount,
                    'compression_ratio' => $compressionRatio,
                    'processing_time_ms' => $processingTime,
                    'model' => $response['model'],
                    'provider' => $response['provider'],
                    'configuration' => [
                        'summary_type' => $summaryType,
                        'length' => $length,
                        'output_language' => $outputLanguage,
                        'focus_areas' => $focusAreas,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Summary generation failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to generate summary: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
