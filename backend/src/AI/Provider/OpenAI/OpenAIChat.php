<?php

namespace App\AI\Provider\OpenAI;

use App\AI\Exception\ProviderException;
use App\AI\Exception\ProviderFailureFactory;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use App\AI\Tool\OpenAiToolShapes;
use Psr\Log\LoggerInterface;

/**
 * OpenAI chat for OpenAIProvider on the Responses API: the request (the
 * system message as instructions, the conversation as input, the reasoning
 * tier, structured output, tools, a previous response), the answer with its
 * tool calls, and the event stream mapped to the provider callback (text,
 * refusal, reasoning, tool-call deltas, finish).
 */
final class OpenAIChat
{
    public function __construct(
        private readonly OpenAIApiClient $api,
        private readonly OpenAIResponses $responses,
        private readonly LoggerInterface $logger,
        private readonly StructuredOutputTranslator $structuredOutputTranslator,
        private readonly bool $storeResponses,
    ) {
    }

    public function chat(array $messages, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'openai');
        }

        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'];
            $isReasoningModel = $this->responses->usesCompletionTokens($model);

            $requestOptions = $this->buildResponsesRequest($messages, $model, $isReasoningModel, $options);

            $response = $this->responses->create($requestOptions);
            $responseArray = $response->toArray();

            $usage = OpenAIResponsesFormat::normalizeResponsesUsage($responseArray);
            $toolCalls = OpenAIResponsesFormat::extractResponsesToolCalls($responseArray);

            $this->logger->info('OpenAI: Chat completed via Responses API', [
                'model' => $model,
                'response_id' => $response->id,
                'usage' => $usage,
            ]);

            $result = [
                'content' => $response->outputText ?? '',
                'response_id' => $this->storeResponses ? $response->id : null,
                'usage' => $usage,
            ];
            if ([] !== $toolCalls) {
                $result['tool_calls'] = $toolCalls;
                $result['finish_reason'] = 'tool_calls';
            }

            return $result;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw (new ProviderFailureFactory())->fromThrowable($e, 'openai', 'chat');
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'openai');
        }

        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'];
            $isReasoningModel = $this->responses->usesCompletionTokens($model);

            $requestOptions = $this->buildResponsesRequest($messages, $model, $isReasoningModel, $options, true);

            $stream = $this->responses->createStreamed($requestOptions);

            [$usage, $responseId] = $this->readStream($stream, $callback);

            return [
                'usage' => $usage,
                'response_id' => $this->storeResponses ? $responseId : null,
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw (new ProviderFailureFactory())->fromThrowable($e, 'openai', 'chat_stream', 'OpenAI streaming error');
        }
    }

    /**
     * Read the event stream: text, refusal, reasoning and tool-call deltas go
     * to the callback as they arrive and the finish reason after the last
     * event.
     *
     * @return array{0: array<string, int>, 1: ?string} the usage and the response id
     */
    private function readStream(iterable $stream, callable $callback): array
    {
        $usage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cached_tokens' => 0,
            'cache_creation_tokens' => 0,
        ];

        $responseId = null;
        $finishReason = null;
        $sawFunctionCall = false;

        foreach ($stream as $event) {
            $eventType = $event->event;
            $eventData = $event->response->toArray();

            switch ($eventType) {
                case 'response.created':
                    $responseId = $eventData['response']['id'] ?? null;
                    break;

                case 'response.output_text.delta':
                    $content = $eventData['delta'] ?? '';
                    if ('' !== $content) {
                        $callback(['type' => 'content', 'content' => $content]);
                    }
                    break;

                case 'response.refusal.delta':
                    $refusal = $eventData['delta'] ?? '';
                    if ('' !== $refusal) {
                        $callback(['type' => 'content', 'content' => $refusal]);
                    }
                    break;

                case 'response.reasoning_summary_text.delta':
                    $reasoningContent = $eventData['delta'] ?? '';
                    if ('' !== $reasoningContent) {
                        $callback(['type' => 'reasoning', 'content' => $reasoningContent]);
                    }
                    break;

                case 'response.output_item.added':
                    if ($this->emitResponsesFunctionCallStart($eventData, $callback)) {
                        $sawFunctionCall = true;
                    }
                    break;

                case 'response.function_call_arguments.delta':
                    $sawFunctionCall = true;
                    $callback([
                        'type' => 'tool_call_delta',
                        'index' => (int) ($eventData['output_index'] ?? 0),
                        'id' => null,
                        'name' => null,
                        'arguments' => is_string($eventData['delta'] ?? null) ? $eventData['delta'] : '',
                    ]);
                    break;

                case 'response.output_item.done':
                    $item = is_array($eventData['item'] ?? null) ? $eventData['item'] : [];
                    if ('function_call' === ($item['type'] ?? '')) {
                        $sawFunctionCall = true;
                    }
                    break;

                case 'response.completed':
                    $usage = OpenAIResponsesFormat::normalizeResponsesUsage($eventData['response'] ?? []);
                    $responseId = $eventData['response']['id'] ?? $responseId;
                    $status = $eventData['response']['status'] ?? 'completed';
                    $finishReason = ('completed' === $status) ? 'stop' : 'length';
                    if ($sawFunctionCall || OpenAIResponsesFormat::responsesOutputHasFunctionCall($eventData['response']['output'] ?? [])) {
                        $finishReason = 'tool_calls';
                    }
                    break;

                case 'response.failed':
                    $error = $eventData['response']['error']['message'] ?? 'Unknown error';
                    throw new ProviderException('OpenAI Responses API failed: '.$error, 'openai');
                case 'response.incomplete':
                    $usage = OpenAIResponsesFormat::normalizeResponsesUsage($eventData['response'] ?? []);
                    $responseId = $eventData['response']['id'] ?? $responseId;
                    $finishReason = $sawFunctionCall ? 'tool_calls' : 'length';
                    break;
            }
        }

        if (null !== $finishReason) {
            $callback(['type' => 'finish', 'finish_reason' => $finishReason]);
        }

        return [$usage, $responseId];
    }

    /**
     * Build the Responses API request from the standard messages array.
     *
     * Extracts the system message into `instructions` and converts
     * user/assistant messages into the `input` field.
     */
    private function buildResponsesRequest(array $messages, string $model, bool $isReasoningModel, array $options, bool $stream = false): array
    {
        $systemMessage = OpenAIResponsesFormat::extractSystemMessage($messages);
        $input = OpenAIResponsesFormat::convertToResponsesFormat(OpenAIResponsesFormat::removeSystemMessages($messages));

        if (empty($input)) {
            $input = [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Start']]]];
        }

        $requestOptions = [
            'model' => $model,
            'input' => $input,
            'store' => $this->storeResponses,
            'max_output_tokens' => $options['max_tokens'] ?? OpenAIResponses::DEFAULT_MAX_TOKENS,
        ];

        if (null !== $systemMessage) {
            $requestOptions['instructions'] = $systemMessage;
        }

        if (!$isReasoningModel) {
            $requestOptions['temperature'] = $options['temperature'] ?? 0.7;
        }

        if ($isReasoningModel) {
            $reasoningConfig = $this->responses->resolveReasoningConfig($model, $isReasoningModel, $options);
            if (null !== $reasoningConfig) {
                $requestOptions['reasoning'] = $reasoningConfig;
            }
        }

        if (isset($options['previous_response_id'])) {
            $requestOptions['previous_response_id'] = $options['previous_response_id'];
        }

        $schema = $options['structured_output'] ?? null;
        if ($schema instanceof StructuredOutputSchema) {
            $requestOptions = array_merge($requestOptions, $this->structuredOutputTranslator->translate(OpenAIApiClient::PROVIDER, $model, $stream, $schema));
        }

        if (isset($options['tools']) && is_array($options['tools']) && [] !== $options['tools']) {
            $requestOptions['tools'] = OpenAiToolShapes::toResponsesTools($options['tools']);
        }
        if (array_key_exists('tool_choice', $options)) {
            $mappedChoice = OpenAiToolShapes::toResponsesToolChoice($options['tool_choice']);
            if (null !== $mappedChoice) {
                $requestOptions['tool_choice'] = $mappedChoice;
            }
        }
        if (array_key_exists('parallel_tool_calls', $options)) {
            $requestOptions['parallel_tool_calls'] = (bool) $options['parallel_tool_calls'];
        }

        return $requestOptions;
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function emitResponsesFunctionCallStart(array $eventData, callable $callback): bool
    {
        $item = is_array($eventData['item'] ?? null) ? $eventData['item'] : [];
        if ('function_call' !== ($item['type'] ?? '')) {
            return false;
        }

        $callback([
            'type' => 'tool_call_delta',
            'index' => (int) ($eventData['output_index'] ?? 0),
            'id' => isset($item['call_id']) && is_string($item['call_id']) && '' !== $item['call_id'] ? $item['call_id'] : null,
            'name' => isset($item['name']) && is_string($item['name']) && '' !== $item['name'] ? $item['name'] : null,
            'arguments' => is_string($item['arguments'] ?? null) ? $item['arguments'] : '',
        ]);

        return true;
    }
}
