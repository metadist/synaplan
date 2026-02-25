<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;
use Inference\InferTensorContents;
use Inference\ModelInferRequest;
use Inference\ModelInferRequest\InferInputTensor;
use Inference\ModelInferRequest\InferRequestedOutputTensor;
use Psr\Log\LoggerInterface;

/**
 * gRPC client for Triton Inference Server.
 */
class GRPCInferenceServiceClient extends BaseStub
{
    public function __construct(string $hostname, array $opts, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    public function ModelStreamInfer(
        ModelInferRequest $argument,
        array $metadata = [],
        array $options = [],
    ) {
        return $this->_serverStreamRequest(
            '/inference.GRPCInferenceService/ModelStreamInfer',
            $argument,
            ['\Inference\ModelStreamInferResponse', 'decode'],
            $metadata,
            $options
        );
    }

    public function ModelInfer(
        ModelInferRequest $argument,
        array $metadata = [],
        array $options = [],
    ) {
        return $this->_simpleRequest(
            '/inference.GRPCInferenceService/ModelInfer',
            $argument,
            ['\Inference\ModelInferResponse', 'decode'],
            $metadata,
            $options
        );
    }
}

/**
 * Triton Inference Server Provider.
 *
 * Handles AI inference via NVIDIA Triton Inference Server using gRPC.
 * Chat requests go through the "streaming" BLS wrapper which applies
 * chat templates and handles harmony channel detection.
 * Embedding requests go directly to the embedding model (e.g. bge-m3).
 */
class TritonProvider implements ChatProviderInterface, EmbeddingProviderInterface
{
    private ?GRPCInferenceServiceClient $client = null;

    public function __construct(
        private LoggerInterface $logger,
        private string $serverUrl,
    ) {
        $this->initClient();
    }

    /**
     * Initialize the gRPC client connection.
     */
    private function initClient(): bool
    {
        if (empty($this->serverUrl)) {
            // Debug level: Triton is optional, no need to warn on every request
            $this->logger->debug('Triton server URL not configured');

            return false;
        }

        try {
            $this->client = new GRPCInferenceServiceClient(
                $this->serverUrl,
                ['credentials' => ChannelCredentials::createInsecure()]
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize Triton client', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getName(): string
    {
        return 'triton';
    }

    public function getDisplayName(): string
    {
        return 'NVIDIA Triton';
    }

    public function getDescription(): string
    {
        return 'High-performance inference server for AI models via gRPC';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding'];
    }

    public function getDefaultModels(): array
    {
        return []; // Models come from DB (BMODELS), not provider
    }

    public function getStatus(): array
    {
        if (!$this->client) {
            return [
                'healthy' => false,
                'error' => 'Triton client not initialized',
            ];
        }

        try {
            // Simple health check - try to create a minimal request
            $start = microtime(true);
            // We can't easily ping Triton, so just check if client exists
            $latency = (microtime(true) - $start) * 1000;

            return [
                'healthy' => true,
                'latency_ms' => round($latency, 2),
                'error_rate' => 0.0,
                'active_connections' => 0,
                'server_url' => $this->serverUrl,
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function isAvailable(): bool
    {
        return null !== $this->client && !empty($this->serverUrl);
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'TRITON_SERVER_URL' => [
                'required' => true,
                'hint' => 'Triton gRPC server URL (e.g., triton-server:8001)',
            ],
        ];
    }

    // ==================== Chat ====================

    public function chat(array $messages, array $options = []): string
    {
        if (!$this->client) {
            throw new ProviderException('Triton client not initialized', 'triton');
        }

        if (!isset($options['model'])) {
            throw new ProviderException('Triton provider requires model name to be specified in options', 'triton');
        }

        $model = $options['model'];
        $maxTokens = $options['max_tokens'] ?? 4096;

        try {
            $this->logger->info('Triton chat request', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $prompt = $this->buildPrompt($messages);
            $answer = $this->collectInference($prompt, $model, $maxTokens);

            return $answer;
        } catch (\Exception $e) {
            $this->logger->error('Triton chat error', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);

            throw new ProviderException('Triton chat error: '.$e->getMessage(), 'triton');
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!$this->client) {
            throw new ProviderException('Triton client not initialized', 'triton');
        }

        if (!isset($options['model'])) {
            throw new ProviderException('Triton provider requires model name to be specified in options', 'triton');
        }

        $model = $options['model'];
        $maxTokens = $options['max_tokens'] ?? 4096;

        try {
            $this->logger->info('Triton streaming chat START', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $prompt = $this->buildPrompt($messages);
            $request = $this->createChatInferRequest($prompt, $model, $maxTokens);

            $call = $this->client->ModelStreamInfer($request);

            $chunkCount = 0;
            $fullResponse = '';
            $pendingText = '';
            $pendingChannel = 'content';

            foreach ($call->responses() as $response) {
                // Check for errors first
                $errorMessage = $response->getErrorMessage();
                if (!empty($errorMessage)) {
                    $this->logger->warning('Triton stream error message', [
                        'error' => $errorMessage,
                    ]);
                    continue;
                }

                $inferResponse = $response->getInferResponse();
                if (!$inferResponse) {
                    continue;
                }

                $textChunk = '';
                $channel = 'content';
                $isFinal = false;

                // Try structured output parsing first
                $outputs = $inferResponse->getOutputs();
                foreach ($outputs as $output) {
                    if ('text_output' === $output->getName()) {
                        $contents = $output->getContents();
                        if ($contents) {
                            $bytesContents = $contents->getBytesContents();
                            if (!empty($bytesContents)) {
                                $textChunk = $bytesContents[0];
                            }
                        }
                    } elseif ('channel' === $output->getName()) {
                        $contents = $output->getContents();
                        if ($contents) {
                            $bytesContents = $contents->getBytesContents();
                            if (!empty($bytesContents)) {
                                $channel = $bytesContents[0];
                            }
                        }
                    } elseif ('is_final' === $output->getName()) {
                        $contents = $output->getContents();
                        if ($contents) {
                            $boolContents = $contents->getBoolContents();
                            if (!empty($boolContents)) {
                                $isFinal = $boolContents[0];
                            }
                        }
                    }
                }

                // Fallback to raw contents if no structured output
                // Raw output order matches config.pbtxt: text_output[0], channel[1], is_final[2]
                if (empty($textChunk)) {
                    $rawContents = $inferResponse->getRawOutputContents();
                    if (!empty($rawContents) && isset($rawContents[0])) {
                        $textChunk = $this->decodeProtobufData($rawContents[0]);
                    }
                    if (isset($rawContents[1]) && strlen($rawContents[1]) >= 4) {
                        $channel = $this->decodeProtobufData($rawContents[1]);
                    }
                    if (isset($rawContents[2]) && strlen($rawContents[2]) >= 1) {
                        $isFinal = 0 !== ord($rawContents[2][0]);
                    }
                }

                // Process and send chunk
                if (!empty($textChunk)) {
                    // Clean garbage chars and sanitize UTF-8
                    $textChunk = rtrim($textChunk, "\0\x00-\x1F\x7F-\x9F");
                    $textChunk = mb_convert_encoding($textChunk, 'UTF-8', 'UTF-8');

                    $fullResponse .= $textChunk;

                    // Flush pending text if channel changed
                    if (!empty($pendingText) && $channel !== $pendingChannel) {
                        $callback($this->buildChunk($pendingText, $pendingChannel));
                        $pendingText = '';
                        ++$chunkCount;
                    }

                    $pendingText .= $textChunk;
                    $pendingChannel = $channel;

                    // Stream meaningful chunks
                    if (strlen($pendingText) > 5 || (strlen($pendingText) > 0 && '' !== trim($pendingText))) {
                        $callback($this->buildChunk($pendingText, $pendingChannel));
                        $pendingText = '';
                        ++$chunkCount;

                        if (1 === $chunkCount) {
                            $this->logger->info('Triton: First chunk sent', [
                                'length' => strlen($textChunk),
                                'channel' => $channel,
                            ]);
                        }
                    }
                }

                if ($isFinal) {
                    break;
                }
            }

            // Flush remaining text
            if (!empty($pendingText)) {
                $callback($this->buildChunk($pendingText, $pendingChannel));
            }

            $this->logger->info('Triton: Streaming complete', [
                'chunks_sent' => $chunkCount,
                'total_length' => strlen($fullResponse),
            ]);

            if (empty($fullResponse)) {
                throw new ProviderException('Triton streaming completed with no content', 'triton');
            }
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Triton streaming error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new ProviderException('Triton streaming error: '.$e->getMessage(), 'triton', null, 0, $e);
        }
    }

    // ==================== Embedding ====================

    public function embed(string $text, array $options = []): array
    {
        if (!$this->client) {
            throw new ProviderException('Triton client not initialized', 'triton');
        }

        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'triton');
        }

        $model = $options['model'];

        try {
            $request = $this->createEmbedInferRequest($text, $model);

            /** @var \Grpc\UnaryCall $call */
            $call = $this->client->ModelInfer($request);
            /** @var \Inference\ModelInferResponse $response */
            [$response, $status] = $call->wait();

            if (0 !== $status->code) {
                throw new \RuntimeException('gRPC error: '.$status->details);
            }

            // Parse FP32 embedding from raw output
            $rawContents = $response->getRawOutputContents();
            if (!isset($rawContents[0]) || '' === $rawContents[0]) {
                throw new \RuntimeException('No embedding output returned');
            }

            return $this->decodeFp32Array($rawContents[0]);
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Triton embedding error', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);

            throw new ProviderException('Triton embedding error: '.$e->getMessage(), 'triton');
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        return array_map(fn ($text) => $this->embed($text, $options), $texts);
    }

    public function getDimensions(string $model): int
    {
        return match (true) {
            str_contains($model, 'bge-m3') => 1024,
            default => 1024,
        };
    }

    // ==================== Private helpers ====================

    /**
     * Build prompt from messages array (OpenAI format).
     */
    private function buildPrompt(array $messages): string
    {
        $promptArr = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            $promptArr[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return json_encode($promptArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build a structured chunk for the stream callback.
     *
     * Maps Triton channel names to the type format used by
     * StreamController (matching OpenAI/Anthropic providers).
     */
    private function buildChunk(string $text, string $channel): array
    {
        // analysis/commentary map to reasoning (rendered as <think> by StreamController)
        $type = match ($channel) {
            'analysis', 'commentary' => 'reasoning',
            default => 'content',
        };

        return ['type' => $type, 'content' => $text];
    }

    /**
     * Create a chat inference request targeting the "streaming" BLS wrapper.
     */
    private function createChatInferRequest(string $prompt, string $modelName, int $maxTokens = 4096): ModelInferRequest
    {
        // Conversation input tensor
        $textInput = new InferInputTensor();
        $textInput->setName('conversation');
        $textInput->setDatatype('BYTES');
        $textInput->setShape([1, 1]);

        $textContents = new InferTensorContents();
        $textContents->setBytesContents([$prompt]);
        $textInput->setContents($textContents);

        // Model name tensor — tells the streaming wrapper which backend model to use
        $modelNameInput = new InferInputTensor();
        $modelNameInput->setName('model_name');
        $modelNameInput->setDatatype('BYTES');
        $modelNameInput->setShape([1, 1]);

        $modelNameContents = new InferTensorContents();
        $modelNameContents->setBytesContents([$modelName]);
        $modelNameInput->setContents($modelNameContents);

        // Max tokens tensor
        $maxTokensInput = new InferInputTensor();
        $maxTokensInput->setName('max_tokens');
        $maxTokensInput->setDatatype('INT32');
        $maxTokensInput->setShape([1, 1]);

        $maxContents = new InferTensorContents();
        $maxContents->setIntContents([$maxTokens]);
        $maxTokensInput->setContents($maxContents);

        // Output tensors
        $textOutput = new InferRequestedOutputTensor();
        $textOutput->setName('text_output');

        $channelOutput = new InferRequestedOutputTensor();
        $channelOutput->setName('channel');

        $finalOutput = new InferRequestedOutputTensor();
        $finalOutput->setName('is_final');

        // Always target the "streaming" BLS wrapper model
        $request = new ModelInferRequest();
        $request->setModelName('streaming');
        $request->setId('req-'.uniqid());
        $request->setInputs([$textInput, $modelNameInput, $maxTokensInput]);
        $request->setOutputs([$textOutput, $channelOutput, $finalOutput]);

        return $request;
    }

    /**
     * Create an embedding inference request.
     */
    private function createEmbedInferRequest(string $text, string $modelName): ModelInferRequest
    {
        $textInput = new InferInputTensor();
        $textInput->setName('text_input');
        $textInput->setDatatype('BYTES');
        $textInput->setShape([1, 1]); // batch dim required by max_batch_size > 0

        $textContents = new InferTensorContents();
        $textContents->setBytesContents([$text]);
        $textInput->setContents($textContents);

        $embeddingOutput = new InferRequestedOutputTensor();
        $embeddingOutput->setName('embedding');

        $request = new ModelInferRequest();
        $request->setModelName($modelName);
        $request->setId('emb-'.uniqid());
        $request->setInputs([$textInput]);
        $request->setOutputs([$embeddingOutput]);

        return $request;
    }

    /**
     * Non-streaming chat inference (collects all output into a string).
     */
    private function collectInference(string $prompt, string $modelName, int $maxTokens = 4096): string
    {
        $answer = '';

        try {
            $request = $this->createChatInferRequest($prompt, $modelName, $maxTokens);
            $call = $this->client->ModelStreamInfer($request);

            foreach ($call->responses() as $response) {
                $errorMessage = $response->getErrorMessage();
                if (!empty($errorMessage)) {
                    $this->logger->warning('Triton inference error', ['error' => $errorMessage]);
                    continue;
                }

                $inferResponse = $response->getInferResponse();
                if (!$inferResponse) {
                    continue;
                }

                $textChunk = '';
                $channel = 'content';
                $isFinal = false;

                // Try structured output
                $outputs = $inferResponse->getOutputs();
                foreach ($outputs as $output) {
                    if ('text_output' === $output->getName()) {
                        $contents = $output->getContents();
                        if ($contents) {
                            $bytesContents = $contents->getBytesContents();
                            if (!empty($bytesContents)) {
                                $textChunk = $bytesContents[0];
                            }
                        }
                    } elseif ('channel' === $output->getName()) {
                        $contents = $output->getContents();
                        if ($contents) {
                            $bytesContents = $contents->getBytesContents();
                            if (!empty($bytesContents)) {
                                $channel = $bytesContents[0];
                            }
                        }
                    } elseif ('is_final' === $output->getName()) {
                        $contents = $output->getContents();
                        if ($contents) {
                            $boolContents = $contents->getBoolContents();
                            if (!empty($boolContents)) {
                                $isFinal = $boolContents[0];
                            }
                        }
                    }
                }

                // Fallback to raw contents
                if (empty($textChunk)) {
                    $rawContents = $inferResponse->getRawOutputContents();
                    if (!empty($rawContents) && isset($rawContents[0])) {
                        $textChunk = $this->decodeProtobufData($rawContents[0]);
                    }
                    if (isset($rawContents[1]) && strlen($rawContents[1]) >= 4) {
                        $channel = $this->decodeProtobufData($rawContents[1]);
                    }
                    if (isset($rawContents[2]) && strlen($rawContents[2]) >= 1) {
                        $isFinal = 0 !== ord($rawContents[2][0]);
                    }
                }

                // Only collect final/content channel text (skip reasoning for non-streaming)
                if (!empty($textChunk) && ('final' === $channel || 'content' === $channel)) {
                    $textChunk = rtrim($textChunk, "\0\x00-\x1F\x7F-\x9F");
                    $textChunk = mb_convert_encoding($textChunk, 'UTF-8', 'UTF-8');
                    $answer .= $textChunk;
                }

                if ($isFinal) {
                    break;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Triton inference error', ['error' => $e->getMessage()]);
            throw $e;
        }

        return $answer;
    }

    /**
     * Decode protobuf length-prefixed BYTES data.
     */
    private function decodeProtobufData(string $rawData): string
    {
        if (strlen($rawData) < 4) {
            return $rawData;
        }

        $length = unpack('V', substr($rawData, 0, 4))[1];
        if ($length > 0 && $length <= strlen($rawData) - 4 && $length < 10000) {
            $decodedChunk = substr($rawData, 4, $length);
            if (false === strpos($decodedChunk, "\0") && mb_check_encoding($decodedChunk, 'UTF-8')) {
                return $decodedChunk;
            }
        }

        return $rawData;
    }

    /**
     * Decode raw FP32 binary data into a PHP float array.
     */
    private function decodeFp32Array(string $rawData): array
    {
        $byteLength = strlen($rawData);
        if (0 === $byteLength) {
            return [];
        }

        $floatCount = intdiv($byteLength, 4);
        $embedding = [];

        for ($i = 0; $i < $floatCount; ++$i) {
            $embedding[] = unpack('g', substr($rawData, $i * 4, 4))[1];
        }

        return $embedding;
    }
}
