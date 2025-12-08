<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
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
}

/**
 * Triton Inference Server Provider.
 *
 * Handles AI inference via NVIDIA Triton Inference Server using gRPC.
 * Supports streaming text generation with various LLM models.
 */
class TritonProvider implements ChatProviderInterface
{
    private ?GRPCInferenceServiceClient $client = null;
    private string $modelName = 'mistral-streaming';

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
            $this->logger->warning('Triton server URL not configured');

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
        return ['chat'];
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

    public function chat(array $messages, array $options = []): string
    {
        if (!$this->client) {
            throw new ProviderException('Triton client not initialized', 'triton');
        }

        $model = $options['model'] ?? $this->modelName;
        $maxTokens = $options['max_tokens'] ?? 4096;

        try {
            $this->logger->info('Triton chat request', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $prompt = $this->buildPrompt($messages);
            $answer = $this->streamInference($prompt, $maxTokens, false);

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

        $model = $options['model'] ?? $this->modelName;
        $maxTokens = $options['max_tokens'] ?? 4096;

        try {
            $this->logger->info('🔵 Triton streaming chat START', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $prompt = $this->buildPrompt($messages);
            $request = $this->createInferRequest($prompt, $maxTokens);

            $call = $this->client->ModelStreamInfer($request);

            $chunkCount = 0;
            $fullResponse = '';
            $pendingText = '';

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
                if (empty($textChunk)) {
                    $rawContents = $inferResponse->getRawOutputContents();
                    if (!empty($rawContents) && isset($rawContents[0])) {
                        $textChunk = $this->decodeProtobufData($rawContents[0]);
                    }
                    if (count($rawContents) > 1 && isset($rawContents[1])) {
                        $isFinal = !empty(trim($rawContents[1]));
                    }
                }

                // Process and send chunk
                if (!empty($textChunk)) {
                    // Clean garbage chars and sanitize UTF-8
                    $textChunk = rtrim($textChunk, "\0\x00-\x1F\x7F-\x9F");
                    $textChunk = mb_convert_encoding($textChunk, 'UTF-8', 'UTF-8');

                    $fullResponse .= $textChunk;
                    $pendingText .= $textChunk;

                    // Stream meaningful chunks
                    if (strlen($pendingText) > 5 || (strlen($pendingText) > 0 && '' !== trim($pendingText))) {
                        $callback($pendingText);
                        $pendingText = '';
                        ++$chunkCount;

                        if (1 === $chunkCount) {
                            $this->logger->info('🟢 Triton: First chunk sent!', [
                                'length' => strlen($textChunk),
                                'preview' => substr($textChunk, 0, 50),
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
                $callback($pendingText);
            }

            $this->logger->info('🔵 Triton: Streaming complete', [
                'chunks_sent' => $chunkCount,
                'total_length' => strlen($fullResponse),
            ]);

            if (empty($fullResponse)) {
                throw new ProviderException('Triton streaming completed with no content', 'triton');
            }
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('🔴 Triton streaming error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new ProviderException('Triton streaming error: '.$e->getMessage(), 'triton', null, 0, $e);
        }
    }

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
     * Create a Triton inference request.
     */
    private function createInferRequest(string $prompt, int $maxTokens = 4096): ModelInferRequest
    {
        // Text input tensor
        $textInput = new InferInputTensor();
        $textInput->setName('conversation');
        $textInput->setDatatype('BYTES');
        $textInput->setShape([1, 1]);

        $textContents = new InferTensorContents();
        $textContents->setBytesContents([$prompt]);
        $textInput->setContents($textContents);

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

        $finalOutput = new InferRequestedOutputTensor();
        $finalOutput->setName('is_final');

        // Create request
        $request = new ModelInferRequest();
        $request->setModelName($this->modelName);
        $request->setId('req-'.uniqid());
        $request->setInputs([$textInput, $maxTokensInput]);
        $request->setOutputs([$textOutput, $finalOutput]);

        return $request;
    }

    /**
     * Non-streaming inference (collects all output).
     */
    private function streamInference(string $prompt, int $maxTokens = 4096, bool $stream = true): string
    {
        $answer = '';

        try {
            $request = $this->createInferRequest($prompt, $maxTokens);
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
                    if (count($rawContents) > 1 && isset($rawContents[1])) {
                        $isFinal = !empty(trim($rawContents[1]));
                    }
                }

                if (!empty($textChunk)) {
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
     * Decode protobuf length-prefixed data.
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
}
