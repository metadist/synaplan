<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\Exception\ProviderException;
use App\AI\Provider\OpenAI\OpenAICurlTransport;
use App\AI\Provider\OpenAIProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Tests\Characterization\Support\CleanupSnapshot;
use App\Tests\Characterization\Support\OfflineHttpGuard;
use App\Tests\Characterization\Support\OpenAISdkRecorder;
use App\Tests\Characterization\Support\ProviderHttpRecorder;
use GuzzleHttp\Psr7\Response;
use OpenAI\Responses\Audio\TranscriptionResponse;
use OpenAI\Responses\Audio\TranslationResponse;
use OpenAI\Responses\Chat\CreateResponse as ChatResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingResponse;
use OpenAI\Responses\Images\CreateResponse as ImageResponse;
use OpenAI\Responses\Images\EditResponse;
use OpenAI\Responses\Images\VariationResponse;
use OpenAI\Responses\Models\RetrieveResponse as ModelResponse;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Golden record of every request OpenAIProvider sends, over its three
 * transports: the OpenAI SDK (on a Guzzle mock with history), Symfony's
 * HTTP client and the native cURL call of the gpt-image request (both on
 * recording doubles).
 *
 * Each capability method runs from fixed inputs, buffered and streamed, on
 * the success paths and the failure paths: the requests each transport was
 * sent (method, URL, headers with the key masked, body), the stream events,
 * the parsed result or the error with its provider context, and the audio
 * files written. Input files are fixtures in a temporary upload directory,
 * and OfflineHttpGuard records any request that would leave the process.
 *
 * Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
final class OpenAIProviderRequestCharacterizationTest extends TestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/provider_requests_openai.json';
    private const KEY = 'test-openai-key';
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';
    private const USAGE = ['input_tokens' => 21, 'input_tokens_details' => ['cached_tokens' => 4], 'output_tokens' => 9, 'output_tokens_details' => ['reasoning_tokens' => 3], 'total_tokens' => 30];

    private const TOOLS = [[
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'description' => 'Current weather for a city',
            'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        ],
    ]];

    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/openai-requests-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0o775, true);
        foreach (['photo.png', 'car.png', 'mask.png'] as $name) {
            file_put_contents($this->uploadDir.'/'.$name, base64_decode(self::PIXEL));
        }
        file_put_contents($this->uploadDir.'/voice.mp3', 'ID3-test-audio');
        OfflineHttpGuard::install();
    }

    protected function tearDown(): void
    {
        OfflineHttpGuard::uninstall();
        foreach (glob($this->uploadDir.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->uploadDir);
    }

    public function testEveryRequestMatchesTheSnapshot(): void
    {
        $actual = [];
        foreach (self::cases() as $id => $case) {
            $actual[$id] = $this->outcome($case);
        }

        self::assertSame([], OfflineHttpGuard::attempts(), 'A request left the process.');
        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'OpenAI provider requests');
    }

    /**
     * @param array{provider?: string, sdk?: list<Response>, http?: list<MockResponse>, curl?: list<array{0: string|false, 1: int, 2: string}>, call: \Closure} $case
     *
     * @return array<string, mixed>
     */
    private function outcome(array $case): array
    {
        $sdk = new OpenAISdkRecorder($case['sdk'] ?? [], [self::KEY]);
        $http = new ProviderHttpRecorder($case['http'] ?? [], [self::KEY]);
        $curlAnswers = $case['curl'] ?? [];
        $curlRequests = [];
        $curl = $this->createStub(OpenAICurlTransport::class);
        $curl->method('postJson')->willReturnCallback(static function (string $url, ?string $key, string|false $body, int $timeout) use (&$curlAnswers, &$curlRequests): array {
            $curlRequests[] = ['url' => $url, 'key' => self::KEY === $key ? ProviderHttpRecorder::SECRET : $key, 'body' => json_decode((string) $body, true), 'body_sha1' => sha1((string) $body), 'timeout' => $timeout];

            return array_shift($curlAnswers) ?? ['', 0, 'unexpected cURL request'];
        });

        $provider = new OpenAIProvider(
            new NullLogger(),
            $http->client(),
            'none' === ($case['provider'] ?? null) ? null : self::KEY,
            $this->uploadDir,
            'stored' === ($case['provider'] ?? null),
            sdkFactory: $sdk->factory(),
            curl: $curl,
        );

        $events = [];
        $onEvent = static function (mixed $event) use (&$events): void {
            $events[] = $event;
        };

        try {
            $outcome = ['result' => ($case['call'])($provider, $onEvent, $this->uploadDir)];
        } catch (\Throwable $e) {
            $outcome = ['error' => self::error($e)];
        }

        self::assertSame([], $curlAnswers, 'Queued cURL answers that were never requested.');

        return $this->normalize(array_filter([
            'sdk' => $sdk->requests(),
            'http' => $http->requests(),
            'curl' => $curlRequests,
            'events' => $events,
        ], static fn (array $part): bool => [] !== $part) + $outcome);
    }

    /**
     * @return array<string, array{provider?: string, sdk?: list<Response>, http?: list<MockResponse>, curl?: list<array{0: string|false, 1: int, 2: string}>, call: \Closure}>
     */
    private static function cases(): array
    {
        return self::chatCases() + self::streamCases() + self::embeddingCases() + self::imageCases() + self::visionCases() + self::speechCases() + self::metaCases();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function chatCases(): array
    {
        $chat = static fn (array $messages, array $options): \Closure => static fn (OpenAIProvider $p): array => $p->chat($messages, $options);

        return [
            'chat.text' => [
                'sdk' => [self::model('gpt-4o'), self::response([self::message('Berlin is sunny.')])],
                'call' => $chat([['role' => 'system', 'content' => 'You are terse.'], ['role' => 'user', 'content' => 'Weather in Berlin?']], ['model' => 'gpt-4o', 'temperature' => 0.3, 'max_tokens' => 200]),
            ],
            'chat.reasoning_tools' => [
                'sdk' => [self::model('gpt-5.5'), self::response([self::message('Let me check.'), self::functionCall('get_weather', '{"city":"Berlin"}', 'call_w2')])],
                'call' => $chat(self::conversation(), ['model' => 'gpt-5.5', 'reasoning_effort' => 'low', 'tools' => self::TOOLS, 'tool_choice' => ['type' => 'function', 'function' => ['name' => 'get_weather']], 'parallel_tool_calls' => false]),
            ],
            'chat.stored_structured_previous_response' => [
                'provider' => 'stored',
                'sdk' => [self::model('gpt-4o'), self::response([self::message('{"topic":"weather"}')], ['id' => 'resp_stored_1'])],
                'call' => $chat([['role' => 'user', 'content' => 'Earlier question'], ['role' => 'assistant', 'content' => 'Earlier answer'], ['role' => 'user', 'content' => 'Sort this.']], ['model' => 'gpt-4o', 'previous_response_id' => 'resp_prev_1', 'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object', 'properties' => ['topic' => ['type' => 'string']], 'required' => ['topic']])]),
            ],
            'chat.reasoning_tier_rejected' => [
                'sdk' => [self::model('gpt-5'), self::error400("Unsupported value: 'none' is not supported with the 'gpt-5' model. Supported values are: 'minimal', 'low', 'medium', and 'high'."), self::response([self::message('Done.')])],
                'call' => $chat([['role' => 'user', 'content' => 'Quick one']], ['model' => 'gpt-5', 'reasoning_effort' => 'none']),
            ],
            'chat.previous_response_expired' => [
                'sdk' => [self::model('gpt-4o'), self::error400("Previous response with id 'resp_gone' not found."), self::response([self::message('Fresh start.')])],
                'call' => $chat([['role' => 'user', 'content' => 'First'], ['role' => 'assistant', 'content' => 'Reply'], ['role' => 'user', 'content' => 'Second']], ['model' => 'gpt-4o', 'previous_response_id' => 'resp_gone']),
            ],
            'chat.model_lookup_fails' => [
                'sdk' => [self::sdkError(404, 'The model does not exist.'), self::response([self::message('Hi.')])],
                'call' => $chat([['role' => 'user', 'content' => 'Hi']], ['model' => 'o3-mini']),
            ],
            'chat.http_error' => [
                'sdk' => [self::model('gpt-4o'), self::sdkError(500, 'The server had an error.', 'server_error')],
                'call' => $chat([['role' => 'user', 'content' => 'Hi']], ['model' => 'gpt-4o']),
            ],
            'chat.no_model' => [
                'call' => $chat([['role' => 'user', 'content' => 'Hi']], []),
            ],
            'chat.no_key' => [
                'provider' => 'none',
                'call' => $chat([['role' => 'user', 'content' => 'Hi']], ['model' => 'gpt-4o']),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function streamCases(): array
    {
        $stream = static fn (array $messages, array $options): \Closure => static fn (OpenAIProvider $p, \Closure $onEvent): array => $p->chatStream($messages, $onEvent, $options);
        $created = ['type' => 'response.created', 'response' => self::responseArray([], ['id' => 'resp_s1', 'status' => 'in_progress', 'usage' => null])];
        $completed = static fn (string $status = 'completed', array $output = []): array => ['type' => 'response.completed', 'response' => self::responseArray($output, ['id' => 'resp_s1', 'status' => $status])];
        $text = static fn (string $delta): array => ['type' => 'response.output_text.delta', 'item_id' => 'msg_s1', 'output_index' => 0, 'content_index' => 0, 'delta' => $delta];

        return [
            'stream.text_and_reasoning' => [
                'sdk' => [self::model('gpt-5.5'), self::sse($created, ['type' => 'response.reasoning_summary_text.delta', 'item_id' => 'rs_1', 'output_index' => 0, 'summary_index' => 0, 'delta' => 'Checking the sky.'], $text('Berlin is '), $text('sunny.'), $completed())],
                'call' => $stream([['role' => 'user', 'content' => 'Weather in Berlin?']], ['model' => 'gpt-5.5', 'reasoning_effort' => 'high']),
            ],
            'stream.tool_call' => [
                'sdk' => [self::model('gpt-4o'), self::sse(
                    $created,
                    ['type' => 'response.output_item.added', 'output_index' => 1, 'item' => self::functionCall('get_weather', '', 'call_s1')],
                    ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'output_index' => 1, 'delta' => '{"city":'],
                    ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'output_index' => 1, 'delta' => '"Paris"}'],
                    ['type' => 'response.output_item.done', 'output_index' => 1, 'item' => self::functionCall('get_weather', '{"city":"Paris"}', 'call_s1')],
                    $completed(),
                )],
                'call' => $stream(self::conversation(), ['model' => 'gpt-4o', 'tools' => self::TOOLS, 'tool_choice' => 'required']),
            ],
            'stream.refusal_incomplete' => [
                'sdk' => [self::model('gpt-4o'), self::sse($created, ['type' => 'response.refusal.delta', 'item_id' => 'msg_s1', 'output_index' => 0, 'content_index' => 0, 'delta' => 'I cannot help.'], ['type' => 'response.incomplete', 'response' => self::responseArray([], ['id' => 'resp_s1', 'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']])])],
                'call' => $stream([['role' => 'user', 'content' => 'Something odd']], ['model' => 'gpt-4o', 'max_tokens' => 16]),
            ],
            'stream.failed' => [
                'sdk' => [self::model('gpt-4o'), self::sse($created, ['type' => 'response.failed', 'response' => self::responseArray([], ['id' => 'resp_s1', 'status' => 'failed', 'error' => ['code' => 'server_error', 'message' => 'The model crashed.']])])],
                'call' => $stream([['role' => 'user', 'content' => 'Hi']], ['model' => 'gpt-4o']),
            ],
            'stream.http_error' => [
                'sdk' => [self::model('gpt-4o'), self::sdkError(429, 'Rate limit reached.', 'rate_limit_error')],
                'call' => $stream([['role' => 'user', 'content' => 'Hi']], ['model' => 'gpt-4o']),
            ],
            'stream.no_key' => [
                'provider' => 'none',
                'call' => $stream([['role' => 'user', 'content' => 'Hi']], ['model' => 'gpt-4o']),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function embeddingCases(): array
    {
        $vectors = static fn (int $count): Response => self::sdkJson(EmbeddingResponse::fake(['data' => array_map(static fn (int $i): array => ['object' => 'embedding', 'index' => $i, 'embedding' => [0.1 * ($i + 1), -0.2, 0.3]], range(0, $count - 1)), 'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5]], null, OverrideStrategy::Replace)->toArray());

        return [
            'embedding.single_with_dimensions' => [
                'sdk' => [$vectors(1)],
                'call' => static fn (OpenAIProvider $p): array => $p->embed('Hello world', ['model' => 'text-embedding-3-large', 'dimensions' => 1024]),
            ],
            'embedding.batch_dimensions_ignored' => [
                'sdk' => [$vectors(2)],
                'call' => static fn (OpenAIProvider $p): array => $p->embedBatch(['Hello', 'World'], ['model' => 'text-embedding-ada-002', 'dimensions' => 256]),
            ],
            'embedding.http_error' => [
                'sdk' => [self::sdkError(400, 'Invalid input.')],
                'call' => static fn (OpenAIProvider $p): array => $p->embed('Hello', ['model' => 'text-embedding-3-small']),
            ],
            'embedding.no_model' => [
                'call' => static fn (OpenAIProvider $p): array => $p->embedBatch(['Hello'], []),
            ],
            'embedding.dimensions' => [
                'call' => static fn (OpenAIProvider $p): array => array_map($p->getDimensions(...), ['text-embedding-3-small' => 'text-embedding-3-small', 'text-embedding-3-large' => 'text-embedding-3-large', 'text-embedding-ada-002' => 'text-embedding-ada-002', 'other' => 'other']),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function imageCases(): array
    {
        $gptImageAnswer = [(string) json_encode(['data' => [['b64_json' => self::PIXEL, 'revised_prompt' => 'A red bicycle, studio light']]]), 200, ''];
        $pic2pic = static fn (array $output): MockResponse => new MockResponse((string) json_encode(['output' => $output]), ['response_headers' => ['content-type' => 'application/json']]);

        return [
            'image.dalle3' => [
                'sdk' => [self::sdkJson(ImageResponse::fake(['data' => [['url' => 'https://example.test/dalle.png', 'revised_prompt' => 'A red bicycle', 'b64_json' => null]]], null, OverrideStrategy::Replace)->toArray())],
                'call' => static fn (OpenAIProvider $p): array => $p->generateImage('A red bicycle'),
            ],
            'image.dalle2_size' => [
                'sdk' => [self::sdkJson(ImageResponse::fake(['data' => [['url' => 'https://example.test/one.png', 'revised_prompt' => null, 'b64_json' => null], ['url' => 'https://example.test/two.png', 'revised_prompt' => null, 'b64_json' => null]]], null, OverrideStrategy::Replace)->toArray())],
                'call' => static fn (OpenAIProvider $p): array => $p->generateImage('Two cats', ['model' => 'dall-e-2', 'n' => 2, 'size' => '512x512']),
            ],
            'image.content_policy' => [
                'sdk' => [self::sdkError(400, 'Your request was rejected as a result of our safety system.', 'invalid_request_error', 'content_policy_violation')],
                'call' => static fn (OpenAIProvider $p): array => $p->generateImage('Something forbidden', ['model' => 'dall-e-3']),
            ],
            'image.gpt_image' => [
                'curl' => [$gptImageAnswer],
                'call' => static fn (OpenAIProvider $p): array => $p->generateImage('A red bicycle', ['model' => 'gpt-image-1', 'quality' => 'hd', 'background' => 'transparent', 'size' => '1536x1024']),
            ],
            'image.gpt_image_unsupported_quality' => [
                'curl' => [$gptImageAnswer],
                'call' => static fn (OpenAIProvider $p): array => $p->generateImage('A red bicycle', ['model' => 'gpt-image-1', 'quality' => 'max']),
            ],
            'image.gpt_image_2_5_quality' => [
                'curl' => [[(string) json_encode(['data' => [['url' => 'https://example.test/flare.png']]]), 200, '']],
                'call' => static fn (OpenAIProvider $p): array => $p->generateImage('A lighthouse', ['model' => 'gpt-image-2.5-flare', 'quality' => 'max']),
            ],
            'image.gpt_image_http_error' => [
                'curl' => [[(string) json_encode(['error' => ['message' => 'Billing hard limit reached']]), 400, '']],
                'call' => static fn (OpenAIProvider $p): array => $p->generateImage('A red bicycle', ['model' => 'gpt-image-1']),
            ],
            'image.gpt_image_curl_error' => [
                'curl' => [[false, 0, 'Could not resolve host: api.openai.com']],
                'call' => static fn (OpenAIProvider $p): array => $p->generateImage('A red bicycle', ['model' => 'gpt-image-1']),
            ],
            'image.gpt_image_no_images' => [
                'curl' => [[(string) json_encode(['data' => []]), 200, '']],
                'call' => static fn (OpenAIProvider $p): array => $p->generateImage('A red bicycle', ['model' => 'gpt-image-1']),
            ],
            'image.responses_pic2pic' => [
                'http' => [$pic2pic([['type' => 'reasoning', 'id' => 'rs_1'], ['type' => 'image_generation_call', 'id' => 'ig_1', 'result' => self::PIXEL, 'revised_prompt' => 'A blue car', 'size' => '1536x1024']])],
                'call' => static fn (OpenAIProvider $p, \Closure $onEvent, string $uploads): array => $p->generateImage('Make the car blue', ['model' => 'gpt-image-2.5-flare', 'images' => [$uploads.'/car.png', $uploads.'/photo.png'], 'quality' => 'standard']),
            ],
            'image.responses_prose' => [
                'http' => [$pic2pic([['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'I would rather describe it: a car.']]]])],
                'call' => static fn (OpenAIProvider $p, \Closure $onEvent, string $uploads): array => $p->generateImage('What is it?', ['model' => 'gpt-image-1', 'images' => [$uploads.'/car.png']]),
            ],
            'image.responses_http_error' => [
                'http' => [new MockResponse((string) json_encode(['error' => ['message' => 'Invalid image']]), ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']])],
                'call' => static fn (OpenAIProvider $p, \Closure $onEvent, string $uploads): array => $p->generateImage('Edit', ['model' => 'gpt-5.5', 'images' => [$uploads.'/car.png']]),
            ],
            'image.variations' => [
                'sdk' => [self::sdkJson(VariationResponse::fake(['data' => [['url' => 'https://example.test/var1.png', 'b64_json' => null]]], null, OverrideStrategy::Replace)->toArray())],
                'call' => static fn (OpenAIProvider $p, \Closure $onEvent, string $uploads): array => $p->createVariations($uploads.'/photo.png', 1),
            ],
            'image.edit' => [
                'sdk' => [self::sdkJson(EditResponse::fake(['data' => [['url' => 'https://example.test/edited.png', 'b64_json' => null]]], null, OverrideStrategy::Replace)->toArray())],
                'call' => static fn (OpenAIProvider $p, \Closure $onEvent, string $uploads): string => $p->editImage($uploads.'/photo.png', $uploads.'/mask.png', 'Add a hat'),
            ],
            'image.edit_missing_file' => [
                'call' => static fn (OpenAIProvider $p, \Closure $onEvent, string $uploads): string => @$p->editImage($uploads.'/missing.png', $uploads.'/mask.png', 'Add a hat'),
            ],
            'image.no_key' => [
                'provider' => 'none',
                'call' => static fn (OpenAIProvider $p): array => $p->generateImage('A red bicycle'),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function visionCases(): array
    {
        $chatAnswer = static fn (string $text): Response => self::sdkJson(ChatResponse::fake(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text, 'function_call' => null, 'tool_calls' => []], 'logprobs' => null, 'finish_reason' => 'stop']]])->toArray());

        return [
            'vision.chat_completions' => [
                'sdk' => [self::model('gpt-4o'), $chatAnswer('A single pixel.')],
                'call' => static fn (OpenAIProvider $p): string => $p->analyzeImage('photo.png', 'What is this?', ['max_tokens' => 300]),
            ],
            'vision.explain_default_prompt' => [
                'sdk' => [self::model('gpt-4o'), $chatAnswer('A tiny image.')],
                'call' => static fn (OpenAIProvider $p): string => $p->explainImage('/photo.png'),
            ],
            'vision.extract_text' => [
                'sdk' => [self::model('gpt-4o'), $chatAnswer('')],
                'call' => static fn (OpenAIProvider $p): string => $p->extractTextFromImage('photo.png'),
            ],
            'vision.responses_for_reasoning_models' => [
                'sdk' => [self::model('gpt-5.5'), self::response([self::message('INVOICE 42')])],
                'call' => static fn (OpenAIProvider $p): string => $p->analyzeImage('photo.png', 'Read the text.', ['model' => 'gpt-5.5', 'max_tokens' => 9000]),
            ],
            'vision.responses_without_text' => [
                'sdk' => [self::model('gpt-5.5-pro'), self::response([], ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']])],
                'call' => static fn (OpenAIProvider $p): string => $p->analyzeImage('photo.png', 'Read the text.', ['model' => 'gpt-5.5-pro']),
            ],
            'vision.not_a_chat_model' => [
                'sdk' => [self::model('gpt-4.1-pro'), self::error400('This is not a chat model and thus not supported in the v1/chat/completions endpoint. Did you mean to use v1/completions?'), self::response([self::message('Recovered.')])],
                'call' => static fn (OpenAIProvider $p): string => $p->analyzeImage('photo.png', 'What is this?', ['model' => 'gpt-4.1-pro']),
            ],
            'vision.compare' => [
                'sdk' => [$chatAnswer('Both are single pixels.')],
                'call' => static fn (OpenAIProvider $p): array => $p->compareImages('photo.png', 'car.png'),
            ],
            'vision.missing_file' => [
                'call' => static fn (OpenAIProvider $p): string => $p->analyzeImage('missing.png', 'What is this?'),
            ],
            'vision.http_error' => [
                'sdk' => [self::sdkError(403, 'Permission denied.', 'permission_error')],
                'call' => static fn (OpenAIProvider $p): array => $p->compareImages('photo.png', 'car.png'),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function speechCases(): array
    {
        return [
            'speech.transcribe' => [
                'sdk' => [self::sdkJson(TranscriptionResponse::fake(['task' => 'transcribe', 'language' => 'german', 'duration' => 2.5, 'text' => 'Hallo Welt', 'segments' => []], null, OverrideStrategy::Replace)->toArray())],
                'call' => static fn (OpenAIProvider $p): array => $p->transcribe('voice.mp3', ['language' => 'de']),
            ],
            'speech.transcribe_absolute_path' => [
                'sdk' => [self::sdkJson(TranscriptionResponse::fake(['task' => 'transcribe', 'language' => 'english', 'duration' => 1.0, 'text' => 'Hello', 'segments' => []], null, OverrideStrategy::Replace)->toArray())],
                'call' => static fn (OpenAIProvider $p, \Closure $onEvent, string $uploads): array => $p->transcribe($uploads.'/voice.mp3', ['model' => 'gpt-4o-transcribe']),
            ],
            'speech.transcribe_missing_file' => [
                'call' => static fn (OpenAIProvider $p): array => $p->transcribe('missing.mp3'),
            ],
            'speech.translate' => [
                'sdk' => [self::sdkJson(TranslationResponse::fake(['task' => 'translate', 'language' => 'german', 'duration' => 2.5, 'text' => 'Hello world', 'segments' => []], null, OverrideStrategy::Replace)->toArray())],
                'call' => static fn (OpenAIProvider $p): string => $p->translateAudio('voice.mp3', 'en'),
            ],
            'speech.synthesize' => [
                'sdk' => [new Response(200, ['Content-Type' => 'audio/mpeg'], 'ID3-synthesized')],
                'call' => static fn (OpenAIProvider $p, \Closure $onEvent, string $uploads): array => self::audioFile($uploads, $p->synthesize('Hallo Welt', ['voice' => 'nova', 'format' => 'opus', 'speed' => 1.2, 'model' => 'tts-1-hd'])),
            ],
            'speech.synthesize_error' => [
                'sdk' => [self::sdkError(401, 'Incorrect API key provided.', 'invalid_request_error', 'invalid_api_key')],
                'call' => static fn (OpenAIProvider $p): string => $p->synthesize('Hallo Welt'),
            ],
            'speech.synthesize_stream' => [
                'http' => [new MockResponse(['ID3-', 'streamed'], ['response_headers' => ['content-type' => 'audio/mpeg']])],
                'call' => static fn (OpenAIProvider $p): array => iterator_to_array($p->synthesizeStream('Hallo Welt', ['voice' => 'echo', 'format' => 'aac']), false),
            ],
            'speech.synthesize_stream_error' => [
                'http' => [new MockResponse((string) json_encode(['error' => ['message' => 'Incorrect API key provided.']]), ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']])],
                'call' => static fn (OpenAIProvider $p): array => iterator_to_array($p->synthesizeStream('Hallo Welt'), false),
            ],
            'speech.no_key' => [
                'provider' => 'none',
                'call' => static fn (OpenAIProvider $p): array => iterator_to_array($p->synthesizeStream('Hallo Welt'), false),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function metaCases(): array
    {
        $describe = static fn (OpenAIProvider $p): array => [
            'name' => $p->getName(),
            'display_name' => $p->getDisplayName(),
            'description' => $p->getDescription(),
            'capabilities' => $p->getCapabilities(),
            'default_models' => $p->getDefaultModels(),
            'required_env' => $p->getRequiredEnvVars(),
            'status' => $p->getStatus(),
            'available' => $p->isAvailable(),
            'tool_calling' => ['gpt-5.4' => $p->supportsToolCalling('gpt-5.4'), 'not-in-catalog' => $p->supportsToolCalling('not-in-catalog')],
            'stream_content_types' => array_map(static fn (string $format): string => $p->getStreamContentType(['format' => $format]), ['mp3' => 'mp3', 'opus' => 'opus', 'aac' => 'aac', 'flac' => 'flac', 'wav' => 'wav']),
            'streams_speech' => $p->supportsStreaming(),
            'voices' => $p->getVoices(),
        ];

        return [
            'meta.with_key' => ['call' => $describe],
            'meta.without_key' => ['provider' => 'none', 'call' => $describe],
        ];
    }

    /**
     * Every message shape the Responses conversion handles: a system turn, a
     * tool call and its result, an image part and a part it passes through.
     *
     * @return list<array<string, mixed>>
     */
    private static function conversation(): array
    {
        return [
            ['role' => 'system', 'content' => 'You are terse.'],
            ['role' => 'user', 'content' => 'What is the weather in Berlin?'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_w1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_w1', 'content' => ['temp' => 18]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'And this photo?'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.self::PIXEL]], ['type' => 'input_file', 'file_id' => 'file_1']]],
        ];
    }

    private static function model(string $id): Response
    {
        return self::sdkJson(ModelResponse::fake(['id' => $id])->toArray());
    }

    /**
     * @param list<array<string, mixed>> $output
     * @param array<string, mixed>       $override
     *
     * @return array<string, mixed>
     */
    private static function responseArray(array $output, array $override = []): array
    {
        return CreateResponse::fake(['id' => 'resp_1', 'output' => $output, 'tools' => [], 'usage' => self::USAGE] + $override, null, OverrideStrategy::Replace)->toArray();
    }

    /**
     * @param list<array<string, mixed>> $output
     * @param array<string, mixed>       $override
     */
    private static function response(array $output, array $override = []): Response
    {
        return self::sdkJson(self::responseArray($output, $override));
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(string $text): array
    {
        return ['type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function functionCall(string $name, string $arguments, string $callId): array
    {
        return ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => $callId, 'name' => $name, 'arguments' => $arguments, 'status' => 'completed'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function sdkJson(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    private static function sdkError(int $status, string $message, string $type = 'invalid_request_error', ?string $code = null): Response
    {
        return self::sdkJson(['error' => ['message' => $message, 'type' => $type, 'param' => null, 'code' => $code]], $status);
    }

    private static function error400(string $message): Response
    {
        return self::sdkError(400, $message);
    }

    /**
     * @param array<string, mixed> ...$events
     */
    private static function sse(array ...$events): Response
    {
        $body = '';
        foreach ($events as $number => $event) {
            $body .= 'data: '.json_encode($event + ['sequence_number' => $number + 1])."\n\n";
        }

        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private static function audioFile(string $uploads, string $filename): array
    {
        $bytes = (string) file_get_contents($uploads.'/'.$filename);

        return ['filename' => $filename, 'bytes' => \strlen($bytes), 'sha1' => sha1($bytes)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(\Throwable $e): array
    {
        $error = ['class' => (new \ReflectionClass($e))->getShortName(), 'message' => $e->getMessage()];
        if ($e instanceof ProviderException) {
            $error += ['provider' => $e->getProviderName(), 'upstream_status' => $e->getUpstreamStatus(), 'context' => $e->getContext()];
        }
        if (null !== $e->getPrevious()) {
            $error['previous'] = (new \ReflectionClass($e->getPrevious()))->getShortName();
        }

        return $error;
    }

    /**
     * Audio file names are unique per call, a temporary file gets a random
     * name, and the upload directory is temporary.
     *
     * @param array<string, mixed> $outcome
     *
     * @return array<string, mixed>
     */
    private function normalize(array $outcome): array
    {
        array_walk_recursive($outcome, function (mixed &$value): void {
            if (\is_string($value)) {
                $value = preg_replace(['/tts_[0-9a-f]{13}\./', '/^php[A-Za-z0-9]{6,}$/'], ['tts_<unique>.', '<tmpfile>'], str_replace($this->uploadDir, '<uploads>', $value));
            }
        });

        return $outcome;
    }
}
