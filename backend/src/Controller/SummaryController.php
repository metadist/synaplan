<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\PromptRepository;
use App\Service\Summary\DocumentSummarizer;
use App\Service\Summary\SummaryCall;
use App\Service\Summary\SummaryRequest;
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
        private PromptRepository $promptRepository,
        private FileRepository $fileRepository,
        private DocumentSummarizer $summarizer,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/summary/generate',
        summary: 'Generate document summary using AI',
        description: 'Generates a summary from either raw text (via the "text" property) '
            .'or the extracted text of a previously uploaded file (via the "fileId" property). '
            .'Exactly one of the two must be provided.',
        security: [['Bearer' => []]],
        tags: ['Summary']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'text',
                    type: 'string',
                    description: 'Document text to summarize. Mutually exclusive with fileId.',
                    example: 'This is a long document that needs to be summarized...'
                ),
                new OA\Property(
                    property: 'fileId',
                    type: 'integer',
                    description: 'ID of a previously uploaded file whose extracted text '
                        .'should be summarized. The file must belong to the authenticated '
                        .'user and must have been processed with process_level=extract or '
                        .'higher. Mutually exclusive with text.',
                    example: 42
                ),
                new OA\Property(
                    property: 'summaryType',
                    type: 'string',
                    enum: ['abstractive', 'extractive', 'bullet-points', 'translation'],
                    example: 'abstractive',
                    description: 'Type of summary to generate. translation returns the whole document in outputLanguage, nothing left out or added; length and focusAreas do not apply.'
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
    #[OA\Response(response: 404, description: 'File not found (fileId)')]
    #[OA\Response(response: 422, description: 'File has no extracted text yet')]
    #[OA\Response(response: 500, description: 'Summary generation failed')]
    public function generate(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $startTime = microtime(true);

        try {
            $summaryRequest = SummaryRequest::fromPayload(json_decode($request->getContent(), true));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $text = $summaryRequest->text ?? $this->ownedFileText((int) $summaryRequest->fileId, $user);
        if ($text instanceof JsonResponse) {
            return $text;
        }
        $optionsError = $summaryRequest->optionsError();
        if (null !== $optionsError) {
            return $this->json(['success' => false, 'error' => $optionsError], Response::HTTP_BAD_REQUEST);
        }

        $this->logRequest($summaryRequest, $text, (int) $user->getId());

        try {
            $systemPrompt = $this->promptRepository->findOneBy(['topic' => 'docsummary', 'language' => 'en', 'ownerId' => 0]);
            if (!$systemPrompt) {
                $this->logger->error('Doc summary prompt not found in database');

                return $this->json(['success' => false, 'error' => 'Summary service not configured. Please load fixtures.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $result = $this->summarizer->summarize($user, new SummaryCall($summaryRequest->systemPrompt($systemPrompt->getPrompt()), $summaryRequest->message($text), $summaryRequest->temperature(), 'DOC_SUMMARY', $text, $startTime));

            return $this->json($this->summaryPayload($summaryRequest, $text, $result, $startTime, (int) $user->getId()));
        } catch (\Throwable $e) {
            $this->logger->error('Summary generation failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json(['success' => false, 'error' => 'Failed to generate summary: '.$e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function logRequest(SummaryRequest $summaryRequest, string $text, int $userId): void
    {
        $this->logger->info('Summary generation request', [
            'user_id' => $userId,
            'file_id' => $summaryRequest->fileId,
            'text_length' => strlen($text),
            'summary_type' => $summaryRequest->summaryType,
            'length' => $summaryRequest->length,
            'output_language' => $summaryRequest->outputLanguage,
            'focus_areas' => implode(', ', $summaryRequest->focusAreas),
        ]);
    }

    /**
     * The extracted text of a file of the user, or the answer when there is none.
     */
    private function ownedFileText(int $fileId, User $user): string|JsonResponse
    {
        $file = $this->fileRepository->find($fileId);
        if (!$file || $file->getUserId() !== $user->getId()) {
            return $this->json(['success' => false, 'error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        $extracted = $file->getFileText();
        if ('' === trim($extracted)) {
            return $this->json([
                'success' => false,
                'error' => sprintf(
                    'File has no extracted text yet (status: %s). Upload with process_level=extract or higher.',
                    $file->getStatus()
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $extracted;
    }

    /**
     * @param array{summary: string, provider: ?string, model: ?string} $result
     *
     * @return array<string, mixed>
     */
    private function summaryPayload(SummaryRequest $summaryRequest, string $text, array $result, float $startTime, int $userId): array
    {
        $processingTime = (int) ((microtime(true) - $startTime) * 1000);
        $originalWordCount = str_word_count($text);
        $summaryWordCount = str_word_count($result['summary']);
        $compressionRatio = $originalWordCount > 0
            ? round($summaryWordCount / $originalWordCount, 3)
            : 0;

        $this->logger->info('Summary generated successfully', [
            'user_id' => $userId,
            'original_words' => $originalWordCount,
            'summary_words' => $summaryWordCount,
            'compression_ratio' => $compressionRatio,
            'processing_time_ms' => $processingTime,
            'provider' => $result['provider'],
            'model' => $result['model'],
        ]);

        return [
            'success' => true,
            'summary' => $result['summary'],
            'metadata' => [
                'original_length' => $originalWordCount,
                'summary_length' => $summaryWordCount,
                'compression_ratio' => $compressionRatio,
                'processing_time_ms' => $processingTime,
                'model' => $result['model'],
                'provider' => $result['provider'],
                'configuration' => [
                    'summary_type' => $summaryRequest->summaryType,
                    'length' => $summaryRequest->length,
                    'output_language' => $summaryRequest->outputLanguage,
                    'focus_areas' => $summaryRequest->focusAreas,
                ],
            ],
        ];
    }
}
