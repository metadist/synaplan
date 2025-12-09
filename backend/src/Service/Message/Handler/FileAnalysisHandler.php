<?php

namespace App\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * File Analysis Handler.
 *
 * Handles file analysis requests:
 * - For documents (PDF, DOCX, etc.) with pre-extracted text: Uses Chat AI
 * - For images without extracted text: Uses Vision AI
 */
#[AutoconfigureTag('app.message.handler')]
class FileAnalysisHandler implements MessageHandlerInterface
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg'];

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function getName(): string
    {
        return 'file_analysis';
    }

    /**
     * Non-streaming handle method.
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
    ): array {
        $this->notify($progressCallback, 'analyzing', 'Analyzing file...');

        $userPrompt = $message->getText();

        // Get file info
        $fileInfo = $this->getFileInfo($message);

        if (!$fileInfo) {
            $this->logger->error('FileAnalysisHandler: No file found', [
                'message_id' => $message->getId(),
            ]);

            return [
                'content' => 'No file was provided for analysis. Please upload a file and try again.',
                'metadata' => ['error' => 'no_file'],
            ];
        }

        $this->logger->info('FileAnalysisHandler: Processing file', [
            'file_id' => $fileInfo['id'],
            'file_name' => $fileInfo['name'],
            'file_type' => $fileInfo['type'],
            'has_extracted_text' => !empty($fileInfo['text']),
            'extracted_text_length' => strlen($fileInfo['text'] ?? ''),
            'is_image' => $fileInfo['is_image'],
        ]);

        // Decision: Use Chat AI for documents with extracted text, Vision AI for images
        if (!$fileInfo['is_image'] && !empty($fileInfo['text'])) {
            // Document with pre-extracted text → Use Chat Model
            return $this->handleWithChatModel($message, $fileInfo, $userPrompt, $classification, $progressCallback);
        } else {
            // Image or document without text → Use Vision Model
            return $this->handleWithVisionModel($message, $fileInfo, $userPrompt, $classification, $progressCallback);
        }
    }

    /**
     * Handle with streaming support.
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $this->notify($progressCallback, 'analyzing', 'Analyzing file...');

        $userPrompt = $message->getText();

        // Get file info
        $fileInfo = $this->getFileInfo($message);

        if (!$fileInfo) {
            $this->logger->error('FileAnalysisHandler: No file found (streaming)', [
                'message_id' => $message->getId(),
            ]);

            $streamCallback('No file was provided for analysis. Please upload a file and try again.');

            return [
                'metadata' => ['error' => 'no_file'],
            ];
        }

        $this->logger->info('FileAnalysisHandler: Processing file (streaming)', [
            'file_id' => $fileInfo['id'],
            'file_name' => $fileInfo['name'],
            'file_type' => $fileInfo['type'],
            'has_extracted_text' => !empty($fileInfo['text']),
            'extracted_text_length' => strlen($fileInfo['text'] ?? ''),
            'is_image' => $fileInfo['is_image'],
        ]);

        // Decision: Use Chat AI for documents with extracted text, Vision AI for images
        if (!$fileInfo['is_image'] && !empty($fileInfo['text'])) {
            // Document with pre-extracted text → Use Chat Model with streaming
            return $this->handleStreamWithChatModel($message, $fileInfo, $userPrompt, $classification, $streamCallback, $progressCallback, $options);
        } else {
            // Image or document without text → Use Vision Model (non-streaming, then output)
            return $this->handleStreamWithVisionModel($message, $fileInfo, $userPrompt, $classification, $streamCallback, $progressCallback);
        }
    }

    /**
     * Handle document with pre-extracted text using Chat Model.
     */
    private function handleWithChatModel(
        Message $message,
        array $fileInfo,
        string $userPrompt,
        array $classification,
        ?callable $progressCallback,
    ): array {
        $this->notify($progressCallback, 'generating', 'Analyzing document content...');

        // Build context with extracted file content
        $systemPrompt = "You are analyzing a document. The user has uploaded a file and wants to know about its contents.\n\n";
        $systemPrompt .= "=== FILE INFORMATION ===\n";
        $systemPrompt .= "Filename: {$fileInfo['name']}\n";
        $systemPrompt .= "Type: {$fileInfo['type']}\n\n";
        $systemPrompt .= "=== EXTRACTED CONTENT ===\n";
        $systemPrompt .= $fileInfo['text']."\n";
        $systemPrompt .= "=== END OF CONTENT ===\n\n";
        $systemPrompt .= 'Answer the user\'s question about this document. If they ask what\'s in the file, summarize the key points.';

        $finalPrompt = !empty($userPrompt) ? $userPrompt : 'What is in this document? Please summarize the content.';

        // Get Chat model (not Vision model!)
        $modelId = $classification['model_id'] ?? $this->modelConfigService->getDefaultModel('CHAT', $message->getUserId());
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
        }

        $this->logger->info('FileAnalysisHandler: Using Chat model for document', [
            'model_id' => $modelId,
            'provider' => $provider,
            'model' => $modelName,
        ]);

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $finalPrompt],
            ];

            $result = $this->aiFacade->chat(
                $messages,
                $message->getUserId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'max_tokens' => 4000,
                ]
            );

            $this->notify($progressCallback, 'complete', 'Analysis complete.');

            return [
                'content' => $result['content'],
                'metadata' => [
                    'provider' => $result['provider'] ?? $provider ?? 'unknown',
                    'model' => $result['model'] ?? $modelName ?? 'unknown',
                    'model_id' => $modelId,
                    'analyzed_file' => $fileInfo['name'],
                    'analysis_type' => 'chat_with_extracted_text',
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Chat analysis failed', [
                'error' => $e->getMessage(),
                'file' => $fileInfo['name'],
            ]);

            return [
                'content' => 'Document analysis failed: '.$e->getMessage(),
                'metadata' => [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                    'model' => $modelName,
                ],
            ];
        }
    }

    /**
     * Handle document with streaming using Chat Model.
     */
    private function handleStreamWithChatModel(
        Message $message,
        array $fileInfo,
        string $userPrompt,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback,
        array $options,
    ): array {
        $this->notify($progressCallback, 'generating', 'Analyzing document content...');

        // Build context with extracted file content
        $systemPrompt = "You are analyzing a document. The user has uploaded a file and wants to know about its contents.\n\n";
        $systemPrompt .= "=== FILE INFORMATION ===\n";
        $systemPrompt .= "Filename: {$fileInfo['name']}\n";
        $systemPrompt .= "Type: {$fileInfo['type']}\n\n";
        $systemPrompt .= "=== EXTRACTED CONTENT ===\n";
        $systemPrompt .= $fileInfo['text']."\n";
        $systemPrompt .= "=== END OF CONTENT ===\n\n";
        $systemPrompt .= 'Answer the user\'s question about this document. If they ask what\'s in the file, summarize the key points.';

        $finalPrompt = !empty($userPrompt) ? $userPrompt : 'What is in this document? Please summarize the content.';

        // Get Chat model (not Vision model!)
        $modelId = $classification['model_id'] ?? $this->modelConfigService->getDefaultModel('CHAT', $message->getUserId());
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
        }

        $this->logger->info('FileAnalysisHandler: Using Chat model for document (streaming)', [
            'model_id' => $modelId,
            'provider' => $provider,
            'model' => $modelName,
        ]);

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $finalPrompt],
            ];

            // Use streaming chat
            $result = $this->aiFacade->chatStream(
                $messages,
                $streamCallback,
                $message->getUserId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'max_tokens' => 4000,
                ]
            );

            $this->notify($progressCallback, 'complete', 'Analysis complete.');

            return [
                'metadata' => [
                    'provider' => $result['provider'] ?? $provider ?? 'unknown',
                    'model' => $result['model'] ?? $modelName ?? 'unknown',
                    'model_id' => $modelId,
                    'analyzed_file' => $fileInfo['name'],
                    'analysis_type' => 'chat_with_extracted_text',
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Chat streaming analysis failed', [
                'error' => $e->getMessage(),
                'file' => $fileInfo['name'],
            ]);

            $streamCallback('Document analysis failed: '.$e->getMessage());

            return [
                'metadata' => [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                    'model' => $modelName,
                ],
            ];
        }
    }

    /**
     * Handle image using Vision Model.
     */
    private function handleWithVisionModel(
        Message $message,
        array $fileInfo,
        string $userPrompt,
        array $classification,
        ?callable $progressCallback,
    ): array {
        $this->notify($progressCallback, 'analyzing', 'Analyzing image...');

        $analysisPrompt = !empty($userPrompt) ? $userPrompt : 'Please describe this image in detail.';

        // Check if file exists
        $fullPath = $this->uploadDir.'/'.$fileInfo['path'];
        if (!file_exists($fullPath)) {
            $this->logger->error('FileAnalysisHandler: File not found on disk', [
                'path' => $fileInfo['path'],
                'full_path' => $fullPath,
            ]);

            return [
                'content' => "File not found: {$fileInfo['name']}",
                'metadata' => ['error' => 'file_not_found'],
            ];
        }

        // Get Vision model (PIC2TEXT)
        $modelId = $classification['model_id'] ?? $this->modelConfigService->getDefaultModel('PIC2TEXT', $message->getUserId());
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
        }

        $this->logger->info('FileAnalysisHandler: Using Vision model for image', [
            'model_id' => $modelId,
            'provider' => $provider,
            'model' => $modelName,
        ]);

        try {
            $result = $this->aiFacade->analyzeImage(
                $fileInfo['path'],
                $analysisPrompt,
                $message->getUserId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'max_tokens' => 4000,
                ]
            );

            $this->notify($progressCallback, 'complete', 'Analysis complete.');

            return [
                'content' => $result['content'],
                'metadata' => [
                    'provider' => $result['provider'] ?? 'unknown',
                    'model' => $result['model'] ?? 'unknown',
                    'model_id' => $modelId,
                    'analyzed_file' => $fileInfo['name'],
                    'analysis_type' => 'vision',
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Vision analysis failed', [
                'error' => $e->getMessage(),
                'file' => $fileInfo['name'],
            ]);

            return [
                'content' => 'Image analysis failed: '.$e->getMessage(),
                'metadata' => [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                    'model' => $modelName,
                ],
            ];
        }
    }

    /**
     * Handle image with streaming using Vision Model.
     */
    private function handleStreamWithVisionModel(
        Message $message,
        array $fileInfo,
        string $userPrompt,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback,
    ): array {
        // Vision AI doesn't support streaming, so get full result and output it
        $result = $this->handleWithVisionModel($message, $fileInfo, $userPrompt, $classification, $progressCallback);

        if (isset($result['content'])) {
            $streamCallback($result['content']);
        }

        return [
            'metadata' => $result['metadata'] ?? [],
        ];
    }

    /**
     * Get file information from message.
     */
    private function getFileInfo(Message $message): ?array
    {
        // Check for files in the MessageFiles relation
        $files = $message->getFiles();
        if ($files->count() > 0) {
            /** @var File $file */
            $file = $files->first();

            $fileType = strtolower($file->getFileType());
            $isImage = in_array($fileType, self::IMAGE_EXTENSIONS, true);

            // Normalize path
            $filePath = $file->getFilePath();
            if (!str_starts_with($filePath, 'uploads/') && str_contains($filePath, '/uploads/')) {
                $filePath = 'uploads/'.substr($filePath, strpos($filePath, '/uploads/') + 9);
            }

            return [
                'id' => $file->getId(),
                'name' => $file->getFileName(),
                'type' => $file->getFileType(),
                'path' => $filePath,
                'text' => $file->getFileText(), // Pre-extracted text!
                'is_image' => $isImage,
            ];
        }

        // Legacy: Check for file path in message
        $filePath = $message->getFilePath();
        if ($filePath) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $isImage = in_array($extension, self::IMAGE_EXTENSIONS, true);

            // Normalize path
            if (!str_starts_with($filePath, 'uploads/') && str_contains($filePath, '/uploads/')) {
                $filePath = 'uploads/'.substr($filePath, strpos($filePath, '/uploads/') + 9);
            }

            return [
                'id' => null,
                'name' => basename($filePath),
                'type' => $extension,
                'path' => $filePath,
                'text' => '', // No pre-extracted text for legacy
                'is_image' => $isImage,
            ];
        }

        return null;
    }

    /**
     * Notify progress callback.
     */
    private function notify(?callable $callback, string $status, string $message): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'timestamp' => time(),
            ]);
        }
    }
}
