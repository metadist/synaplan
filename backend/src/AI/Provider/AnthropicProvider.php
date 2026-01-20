<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Anthropic Claude Provider.
 *
 * Supports:
 * - Chat (streaming and non-streaming)
 * - Vision/Image Analysis
 * - Extended Thinking (reasoning)
 * - System messages
 * - Tool use (function calling)
 */
class AnthropicProvider implements ChatProviderInterface, VisionProviderInterface
{
    private const API_VERSION = '2023-06-01';
    private const BASE_URL = 'https://api.anthropic.com/v1';

    // Extended Thinking models (Claude 3.5 Sonnet and later with thinking support)
    // Note: Extended thinking is a feature that may require specific API access
    private const THINKING_MODELS = [
        'claude-3-5-sonnet',
        'claude-3-5-sonnet-20241022',
        'claude-sonnet-4',
        'claude-sonnet-4-20250514',
        'claude-opus-4',
        'claude-opus-4-20250514',
        'claude-opus-4-1',
        'claude-opus-4-1-20250805',
        'claude-opus-4-5',
        'claude-opus-4-5-20251101',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?string $apiKey = null,
        private int $timeout = 120,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function getName(): string
    {
        return 'anthropic';
    }

    public function getDisplayName(): string
    {
        return 'Anthropic';
    }

    public function getDescription(): string
    {
        return 'Claude models with advanced reasoning capabilities';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => 'claude-3-5-sonnet-20241022',
            'vision' => 'claude-3-5-sonnet-20241022',
        ];
    }

    public function getStatus(): array
    {
        if (empty($this->apiKey)) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 50,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'ANTHROPIC_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API key from https://console.anthropic.com/',
            ],
        ];
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'anthropic');
        }

        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey('anthropic', 'ANTHROPIC_API_KEY');
        }

        try {
            $model = $options['model'];
            $reasoning = $options['reasoning'] ?? false;

            // Separate system message from conversation
            $systemMessage = null;
            $conversationMessages = [];

            foreach ($messages as $message) {
                if (($message['role'] ?? '') === 'system') {
                    $systemMessage = $message['content'];
                } else {
                    $conversationMessages[] = $message;
                }
            }

            $requestBody = [
                'model' => $model,
                'max_tokens' => $options['max_tokens'] ?? 4096,
                'messages' => $conversationMessages,
            ];

            // Add system message if present
            if ($systemMessage) {
                $requestBody['system'] = $systemMessage;
            }

            // Add temperature if specified
            if (isset($options['temperature'])) {
                $requestBody['temperature'] = $options['temperature'];
            }

            // Enable extended thinking if requested and model supports it
            if ($reasoning && $this->supportsThinking($model)) {
                $requestBody['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => 5000, // Configurable thinking budget
                ];

                $this->logger->info('🧠 Anthropic: Extended Thinking enabled', [
                    'model' => $model,
                    'budget_tokens' => 5000,
                ]);
            }

            $this->logger->info('Anthropic: Chat request', [
                'model' => $model,
                'message_count' => count($conversationMessages),
                'has_system' => null !== $systemMessage,
                'thinking' => $reasoning && $this->supportsThinking($model),
            ]);

            $response = $this->httpClient->request('POST', self::BASE_URL.'/messages', [
                'headers' => $this->getHeaders(),
                'json' => $requestBody,
                'timeout' => $this->timeout,
            ]);

            $data = $response->toArray();

            // Extract content blocks
            $textContent = '';
            $thinkingContent = '';

            foreach ($data['content'] ?? [] as $block) {
                $type = $block['type'] ?? '';

                if ('text' === $type) {
                    $textContent .= $block['text'] ?? '';
                } elseif ('thinking' === $type) {
                    $thinkingContent .= $block['thinking'] ?? '';
                }
            }

            $usage = [
                'input_tokens' => $data['usage']['input_tokens'] ?? 0,
                'output_tokens' => $data['usage']['output_tokens'] ?? 0,
            ];

            $this->logger->info('Anthropic: Chat completed', [
                'model' => $model,
                'usage' => $usage,
                'has_thinking' => !empty($thinkingContent),
            ]);

            return $textContent;
        } catch (\Exception $e) {
            $this->logger->error('Anthropic chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown',
            ]);

            throw new ProviderException('Anthropic chat error: '.$e->getMessage(), 'anthropic');
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'anthropic');
        }

        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey('anthropic', 'ANTHROPIC_API_KEY');
        }

        try {
            $model = $options['model'];
            $reasoning = $options['reasoning'] ?? false;

            // Separate system message from conversation
            $systemMessage = null;
            $conversationMessages = [];

            foreach ($messages as $message) {
                if (($message['role'] ?? '') === 'system') {
                    $systemMessage = $message['content'];
                } else {
                    $conversationMessages[] = $message;
                }
            }

            $requestBody = [
                'model' => $model,
                'max_tokens' => $options['max_tokens'] ?? 4096,
                'messages' => $conversationMessages,
                'stream' => true,
            ];

            // Add system message if present
            if ($systemMessage) {
                $requestBody['system'] = $systemMessage;
            }

            // Add temperature if specified
            if (isset($options['temperature'])) {
                $requestBody['temperature'] = $options['temperature'];
            }

            // Enable extended thinking if requested and model supports it
            if ($reasoning && $this->supportsThinking($model)) {
                $requestBody['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => 5000,
                ];

                $this->logger->info('🧠 Anthropic: Extended Thinking enabled for streaming', [
                    'model' => $model,
                    'budget_tokens' => 5000,
                ]);
            }

            $this->logger->info('🔵 Anthropic: Starting streaming chat', [
                'model' => $model,
                'message_count' => count($conversationMessages),
                'has_system' => null !== $systemMessage,
                'thinking' => $reasoning && $this->supportsThinking($model),
            ]);

            // Debug: Log request body
            $this->logger->info('🔍 Anthropic: Request body', [
                'request' => $requestBody,
            ]);

            $response = $this->httpClient->request('POST', self::BASE_URL.'/messages', [
                'headers' => array_merge($this->getHeaders(), [
                    'Accept' => 'text/event-stream',
                ]),
                'json' => $requestBody,
                'timeout' => $this->timeout,
                'buffer' => false, // Don't buffer the response
            ]);

            // Parse SSE stream
            $this->parseSSEStream($response, $callback);

            $this->logger->info('🔵 Anthropic: Streaming completed');
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Try to extract Anthropic error details
            if (method_exists($e, 'getResponse')) {
                try {
                    $response = $e->getResponse();
                    $errorData = $response->toArray(false);

                    if (isset($errorData['error'])) {
                        $anthropicError = $errorData['error'];
                        $errorMessage = sprintf(
                            'Anthropic API Error: %s (type: %s)',
                            $anthropicError['message'] ?? 'Unknown error',
                            $anthropicError['type'] ?? 'unknown'
                        );

                        $this->logger->error('🔴 Anthropic API Error Details', [
                            'error' => $anthropicError,
                        ]);
                    }
                } catch (\Exception $parseError) {
                    // Ignore parse errors
                }
            }

            $this->logger->error('Anthropic streaming error', [
                'error' => $errorMessage,
                'model' => $options['model'] ?? 'unknown',
            ]);

            throw new ProviderException($errorMessage, 'anthropic');
        }
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        $defaultPrompt = 'Describe what you see in this image in detail.';

        return $this->analyzeImage($imageUrl, $prompt ?: $defaultPrompt, $options);
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->analyzeImage($imageUrl, 'Extract all text from this image. Return only the text, nothing else.');
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        // Claude supports multiple images in a single request
        $model = 'claude-3-5-sonnet-20241022';

        try {
            $image1Data = $this->prepareImageData($imageUrl1);
            $image2Data = $this->prepareImageData($imageUrl2);

            $requestBody = [
                'model' => $model,
                'max_tokens' => 1000,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Compare these two images and describe the similarities and differences.',
                        ],
                        [
                            'type' => 'image',
                            'source' => $image1Data,
                        ],
                        [
                            'type' => 'image',
                            'source' => $image2Data,
                        ],
                    ],
                ]],
            ];

            $response = $this->httpClient->request('POST', self::BASE_URL.'/messages', [
                'headers' => $this->getHeaders(),
                'json' => $requestBody,
                'timeout' => $this->timeout,
            ]);

            $data = $response->toArray();
            $comparison = '';

            foreach ($data['content'] ?? [] as $block) {
                if ('text' === $block['type']) {
                    $comparison .= $block['text'] ?? '';
                }
            }

            return [
                'comparison' => $comparison,
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (\Exception $e) {
            throw new ProviderException('Anthropic image comparison error: '.$e->getMessage(), 'anthropic');
        }
    }

    public function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey('anthropic', 'ANTHROPIC_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'claude-3-5-sonnet-20241022';

            $imageData = $this->prepareImageData($imagePath);

            $this->logger->info('Anthropic: Analyzing image', [
                'model' => $model,
                'image' => basename($imagePath),
                'prompt_length' => strlen($prompt),
            ]);

            $requestBody = [
                'model' => $model,
                'max_tokens' => $options['max_tokens'] ?? 1000,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image',
                            'source' => $imageData,
                        ],
                    ],
                ]],
            ];

            $response = $this->httpClient->request('POST', self::BASE_URL.'/messages', [
                'headers' => $this->getHeaders(),
                'json' => $requestBody,
                'timeout' => $this->timeout,
            ]);

            $data = $response->toArray();

            // Extract text content
            $textContent = '';
            foreach ($data['content'] ?? [] as $block) {
                if ('text' === $block['type']) {
                    $textContent .= $block['text'] ?? '';
                }
            }

            return $textContent;
        } catch (\Exception $e) {
            $this->logger->error('Anthropic vision error', [
                'error' => $e->getMessage(),
            ]);

            throw new ProviderException('Anthropic vision error: '.$e->getMessage(), 'anthropic');
        }
    }

    // ==================== PRIVATE HELPERS ====================

    private function getHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ];
    }

    /**
     * Check if model supports extended thinking.
     */
    private function supportsThinking(string $model): bool
    {
        foreach (self::THINKING_MODELS as $thinkingModel) {
            if (str_starts_with($model, $thinkingModel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prepare image data for API request.
     */
    private function prepareImageData(string $imagePath): array
    {
        $baseDir = rtrim($this->uploadDir, '/');
        $fullPath = $baseDir.'/'.ltrim($imagePath, '/');

        if (!file_exists($fullPath)) {
            throw new \Exception("Image file not found: {$fullPath}");
        }

        $imageData = file_get_contents($fullPath);
        $base64Image = base64_encode($imageData);
        $mimeType = mime_content_type($fullPath);

        // Claude accepts: image/jpeg, image/png, image/gif, image/webp
        $mediaType = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/gif' => 'image/gif',
            'image/webp' => 'image/webp',
            default => throw new \Exception("Unsupported image type: {$mimeType}"),
        };

        return [
            'type' => 'base64',
            'media_type' => $mediaType,
            'data' => $base64Image,
        ];
    }

    /**
     * Parse SSE stream and call callback with structured data.
     *
     * Anthropic SSE Events:
     * - message_start: Contains message metadata
     * - content_block_start: New content block (text or thinking)
     * - content_block_delta: Incremental content update
     * - content_block_stop: Content block finished
     * - message_delta: Usage/metadata updates
     * - message_stop: Stream complete
     * - ping: Keep-alive
     * - error: Error occurred
     */
    private function parseSSEStream(ResponseInterface $response, callable $callback): void
    {
        $buffer = '';
        $currentBlockType = null;

        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isLast()) {
                break;
            }

            $content = $chunk->getContent();
            $buffer .= $content;

            // Process complete SSE events (terminated by \n\n)
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $eventData = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $event = $this->parseSSEEvent($eventData);

                if (!$event || !isset($event['type'])) {
                    continue;
                }

                // Process different event types
                switch ($event['type']) {
                    case 'content_block_start':
                        // Track the type of content block (text or thinking)
                        $currentBlockType = $event['data']['content_block']['type'] ?? null;

                        if ('thinking' === $currentBlockType) {
                            $this->logger->info('🧠 Anthropic: Thinking block started');
                        }
                        break;

                    case 'content_block_delta':
                        $delta = $event['data']['delta'] ?? [];
                        $deltaType = $delta['type'] ?? '';

                        if ('text_delta' === $deltaType) {
                            $text = $delta['text'] ?? '';

                            if ('thinking' === $currentBlockType) {
                                // Send as reasoning chunk
                                $callback([
                                    'type' => 'reasoning',
                                    'content' => $text,
                                ]);
                            } else {
                                // Send as regular content
                                $callback([
                                    'type' => 'content',
                                    'content' => $text,
                                ]);
                            }
                        }
                        break;

                    case 'content_block_stop':
                        // Block finished
                        if ('thinking' === $currentBlockType) {
                            $this->logger->info('🧠 Anthropic: Thinking block completed');
                        }
                        $currentBlockType = null;
                        break;

                    case 'message_stop':
                        // Stream complete
                        break;

                    case 'error':
                        $errorMessage = $event['data']['error']['message'] ?? 'Unknown error';
                        throw new \Exception($errorMessage);
                    case 'ping':
                        // Keep-alive, ignore
                        break;
                }
            }
        }
    }

    /**
     * Parse a single SSE event.
     *
     * Format:
     * event: message_start
     * data: {"type":"message_start","message":{...}}
     */
    private function parseSSEEvent(string $eventData): ?array
    {
        $lines = explode("\n", $eventData);
        $event = [
            'type' => null,
            'data' => null,
        ];

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'event:')) {
                $event['type'] = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $jsonData = trim(substr($line, 5));

                if ($jsonData) {
                    $decoded = json_decode($jsonData, true);
                    if (null !== $decoded) {
                        // Use the 'type' from JSON if event type not set
                        if (!$event['type'] && isset($decoded['type'])) {
                            $event['type'] = $decoded['type'];
                        }
                        $event['data'] = $decoded;
                    }
                }
            }
        }

        return $event['type'] ? $event : null;
    }
}
