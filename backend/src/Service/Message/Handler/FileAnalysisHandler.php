<?php

namespace App\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * File Analysis Handler
 * 
 * Handles file analysis requests (image-to-text, document analysis) using Vision AI
 */
#[AutoconfigureTag('app.message.handler')]
class FileAnalysisHandler implements MessageHandlerInterface
{
    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/html/var/uploads'
    ) {}

    public function getName(): string
    {
        return 'file_analysis';
    }

    /**
     * Non-streaming handle method
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null
    ): array {
        $this->notify($progressCallback, 'analyzing', 'Analyzing file...');

        $topic = $classification['topic'] ?? 'analyzefile';
        $language = $classification['language'] ?? 'en';

        // For Vision API: Use user's text directly as the prompt
        // The DB prompt for "analyzefile" is designed for prompt improvement, not vision analysis
        $userPrompt = $message->getText();
        
        if (!empty($userPrompt)) {
            $analysisPrompt = $userPrompt;
        } else {
            // Default fallback if no user prompt
            $analysisPrompt = 'Please extract all text content from this image. Provide only the extracted text without any additional commentary or analysis.';
        }

        // Get the image file path
        $imagePath = $this->getImagePath($message);
        
        if (!$imagePath) {
            $this->logger->error('FileAnalysisHandler: No image file found', [
                'message_id' => $message->getId(),
                'file_path' => $message->getFilePath(),
                'file_flag' => $message->getFile()
            ]);
            
            return [
                'content' => 'No image file was provided for analysis. Please upload an image and try again.',
                'metadata' => ['error' => 'no_image']
            ];
        }

        // Check if file actually exists
        $fullImagePath = $this->uploadDir . '/' . $imagePath;
        if (!file_exists($fullImagePath)) {
            $this->logger->error('FileAnalysisHandler: Image file not found on disk', [
                'relative_path' => $imagePath,
                'full_path' => $fullImagePath,
                'upload_dir' => $this->uploadDir
            ]);
            
            return [
                'content' => "Image file not found at: {$imagePath}\nPlease ensure the file was uploaded correctly.",
                'metadata' => ['error' => 'file_not_found', 'path' => $imagePath]
            ];
        }

        $this->logger->info('FileAnalysisHandler: Analyzing image', [
            'image_path' => $imagePath,
            'full_path' => $fullImagePath,
            'file_exists' => true,
            'file_size' => filesize($fullImagePath),
            'prompt' => substr($analysisPrompt, 0, 100),
            'user_id' => $message->getUserId()
        ]);

        // Determine model to use
        $modelId = null;
        $provider = null;
        $modelName = null;

        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = $classification['model_id'];
            $this->logger->info('FileAnalysisHandler: Using user-selected model', [
                'model_id' => $modelId
            ]);
        } else {
            $modelId = $this->modelConfigService->getDefaultModel('PIC2TEXT', $message->getUserId());
            $this->logger->info('FileAnalysisHandler: Using DB default model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId()
            ]);
        }

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
            
            $this->logger->info('FileAnalysisHandler: Resolved model', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName
            ]);
        }

        try {
            // Use Vision AI to analyze the image
            $result = $this->aiFacade->analyzeImage(
                $imagePath,
                $analysisPrompt,
                $message->getUserId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'max_tokens' => 4000, // Allow longer responses for text extraction
                ]
            );

            $this->notify($progressCallback, 'analyzing', 'Analysis complete.');

            return [
                'content' => $result['content'],
                'metadata' => [
                    'provider' => $result['provider'] ?? 'unknown',
                    'model' => $result['model'] ?? 'unknown',
                    'model_id' => $modelId,
                    'analyzed_file' => $imagePath,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Analysis failed', [
                'error' => $e->getMessage(),
                'image_path' => $imagePath
            ]);

            return [
                'content' => 'Image analysis failed: ' . $e->getMessage(),
                'metadata' => [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                    'model' => $modelName,
                ],
            ];
        }
    }

    /**
     * Handle with streaming support
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = []
    ): array {
        $this->notify($progressCallback, 'analyzing', 'Analyzing file...');

        $topic = $classification['topic'] ?? 'analyzefile';
        $language = $classification['language'] ?? 'en';

        // For Vision API: Use user's text directly as the prompt
        // The DB prompt for "analyzefile" is designed for prompt improvement, not vision analysis
        $userPrompt = $message->getText();
        
        if (!empty($userPrompt)) {
            $analysisPrompt = $userPrompt;
        } else {
            // Default fallback if no user prompt
            $analysisPrompt = 'Please extract all text content from this image. Provide only the extracted text without any additional commentary or analysis.';
        }

        // Get the image file path
        $imagePath = $this->getImagePath($message);
        
        if (!$imagePath) {
            $this->logger->error('FileAnalysisHandler: No image file found', [
                'message_id' => $message->getId(),
                'file_path' => $message->getFilePath(),
                'file_flag' => $message->getFile()
            ]);
            
            $streamCallback('No image file was provided for analysis. Please upload an image and try again.');
            
            return [
                'metadata' => ['error' => 'no_image']
            ];
        }

        // Check if file actually exists
        $fullImagePath = $this->uploadDir . '/' . $imagePath;
        if (!file_exists($fullImagePath)) {
            $this->logger->error('FileAnalysisHandler: Image file not found on disk (streaming)', [
                'relative_path' => $imagePath,
                'full_path' => $fullImagePath,
                'upload_dir' => $this->uploadDir
            ]);
            
            $streamCallback("Image file not found at: {$imagePath}\nPlease ensure the file was uploaded correctly.");
            
            return [
                'metadata' => ['error' => 'file_not_found', 'path' => $imagePath]
            ];
        }

        $this->logger->info('FileAnalysisHandler: Analyzing image (streaming)', [
            'image_path' => $imagePath,
            'full_path' => $fullImagePath,
            'file_exists' => true,
            'file_size' => filesize($fullImagePath),
            'prompt' => substr($analysisPrompt, 0, 100),
            'user_id' => $message->getUserId()
        ]);

        // Determine model to use
        $modelId = null;
        $provider = null;
        $modelName = null;

        if (isset($classification['model_id']) && $classification['model_id']) {
            $modelId = $classification['model_id'];
            $this->logger->info('FileAnalysisHandler: Using user-selected model', [
                'model_id' => $modelId
            ]);
        } else {
            $modelId = $this->modelConfigService->getDefaultModel('PIC2TEXT', $message->getUserId());
            $this->logger->info('FileAnalysisHandler: Using DB default model', [
                'model_id' => $modelId,
                'user_id' => $message->getUserId()
            ]);
        }

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
            
            $this->logger->info('FileAnalysisHandler: Resolved model', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName
            ]);
        }

        try {
            // Use Vision AI to analyze the image
            // Note: analyzeImage doesn't support streaming yet, so we'll get the full result and stream it
            $result = $this->aiFacade->analyzeImage(
                $imagePath,
                $analysisPrompt,
                $message->getUserId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'max_tokens' => 4000, // Allow longer responses for text extraction
                ]
            );

            // Stream the result
            $streamCallback($result['content']);

            $this->notify($progressCallback, 'analyzing', 'Analysis complete.');

            return [
                'metadata' => [
                    'provider' => $result['provider'] ?? 'unknown',
                    'model' => $result['model'] ?? 'unknown',
                    'model_id' => $modelId,
                    'analyzed_file' => $imagePath,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Analysis failed', [
                'error' => $e->getMessage(),
                'image_path' => $imagePath
            ]);

            $errorMessage = 'Image analysis failed: ' . $e->getMessage();
            $streamCallback($errorMessage);

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
     * Get the image file path from the message
     */
    private function getImagePath(Message $message): ?string
    {
        $this->logger->info('FileAnalysisHandler: Getting image path', [
            'message_id' => $message->getId(),
            'legacy_file_path' => $message->getFilePath(),
            'legacy_file_flag' => $message->getFile(),
            'files_collection_count' => $message->getFiles()->count()
        ]);

        // Check for files in the new MessageFiles relation FIRST
        $files = $message->getFiles();
        if ($files->count() > 0) {
            $file = $files->first();
            $fileRelativePath = $file->getFilePath();
            
            $this->logger->info('FileAnalysisHandler: Found file in collection', [
                'file_id' => $file->getId(),
                'file_path' => $fileRelativePath,
                'file_type' => $file->getFileType(),
                'file_name' => $file->getFileName()
            ]);
            
            // The path should already be relative (e.g., "uploads/user_123/file.jpg")
            // But just in case, handle various formats
            if (str_starts_with($fileRelativePath, 'uploads/')) {
                // Already in correct format
                return $fileRelativePath;
            } elseif (str_contains($fileRelativePath, '/uploads/')) {
                // Absolute path, extract relative part
                return 'uploads/' . substr($fileRelativePath, strpos($fileRelativePath, '/uploads/') + 9);
            } else {
                // Assume it's just the filename, return as-is
                return $fileRelativePath;
            }
        }

        // Fallback: Check for legacy file path in message
        $filePath = $message->getFilePath();
        
        if ($filePath) {
            $this->logger->info('FileAnalysisHandler: Using legacy file path', [
                'file_path' => $filePath
            ]);
            
            // Handle various path formats
            if (str_starts_with($filePath, 'uploads/')) {
                return $filePath;
            } elseif (str_contains($filePath, '/uploads/')) {
                return 'uploads/' . substr($filePath, strpos($filePath, '/uploads/') + 9);
            } elseif (str_starts_with($filePath, '/')) {
                // Absolute path without 'uploads', just take filename
                return 'uploads/' . basename($filePath);
            } else {
                // Relative path or filename
                return $filePath;
            }
        }

        $this->logger->warning('FileAnalysisHandler: No file path found in message');
        return null;
    }

    /**
     * Notify progress callback
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

