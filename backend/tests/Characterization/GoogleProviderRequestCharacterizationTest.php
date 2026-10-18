<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\Exception\ProviderException;
use App\AI\Provider\GoogleProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Tests\Characterization\Support\CleanupSnapshot;
use App\Tests\Characterization\Support\ProviderHttpRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Golden record of every request GoogleProvider sends.
 *
 * Each capability method runs from fixed inputs, buffered and streamed, on
 * the success paths and the failure paths: the requests sent (method, URL,
 * headers with the key masked, body and its hash, time limits), the stream
 * events, the parsed result or the error with its provider context, and the
 * audio files written. Input files are fixtures in a temporary upload
 * directory, and the HTTP client is a recorder, so nothing leaves the
 * process. The blocking generateVideo() is recorded on its cancel path,
 * which needs no poll wait; its poll and download are recorded one by one.
 *
 * Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
final class GoogleProviderRequestCharacterizationTest extends TestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/provider_requests_google.json';
    private const KEY = 'test-google-key';
    private const VERTEX_TOKEN = 'test-vertex-token';
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

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
        $this->uploadDir = sys_get_temp_dir().'/google-requests-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0o775, true);
        foreach (['photo.png', 'car.png', 'mask.png'] as $name) {
            file_put_contents($this->uploadDir.'/'.$name, base64_decode(self::PIXEL));
        }
        file_put_contents($this->uploadDir.'/voice.wav', self::wav(str_repeat("\x01\x00", 800), 16000));
        file_put_contents($this->uploadDir.'/voice.mp3', 'ID3-test-audio');
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
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

        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'Google provider requests');
    }

    /**
     * @param array{provider?: string, responses?: list<MockResponse>, call: \Closure} $case
     *
     * @return array<string, mixed>
     */
    private function outcome(array $case): array
    {
        $recorder = new ProviderHttpRecorder($case['responses'] ?? [], [self::KEY, self::VERTEX_TOKEN]);
        $provider = match ($case['provider'] ?? 'key') {
            'vertex' => new GoogleProvider(new NullLogger(), $recorder->client(), self::KEY, 'synaplan-test', 'europe-west4', $this->uploadDir, self::VERTEX_TOKEN),
            'none' => new GoogleProvider(new NullLogger(), $recorder->client(), null, null, 'us-central1', $this->uploadDir),
            default => new GoogleProvider(new NullLogger(), $recorder->client(), self::KEY, null, 'us-central1', $this->uploadDir),
        };

        $events = [];
        $onEvent = static function (mixed $event) use (&$events): void {
            $events[] = $event;
        };

        try {
            $outcome = ['result' => ($case['call'])($provider, $onEvent, $this->uploadDir)];
        } catch (\Throwable $e) {
            $outcome = ['error' => self::error($e)];
        }

        return $this->normalize(array_filter(['requests' => $recorder->requests(), 'events' => $events], static fn (array $part): bool => [] !== $part) + $outcome);
    }

    /**
     * @return array<string, array{provider?: string, responses?: list<MockResponse>, call: \Closure}>
     */
    private static function cases(): array
    {
        return self::chatCases() + self::streamCases() + self::imageCases() + self::videoCases() + self::speechCases() + self::visionCases() + self::metaCases();
    }

    /**
     * @return array<string, array{provider?: string, responses?: list<MockResponse>, call: \Closure}>
     */
    private static function chatCases(): array
    {
        $usage = ['promptTokenCount' => 12, 'candidatesTokenCount' => 5, 'cachedContentTokenCount' => 2];

        return [
            'chat.text' => [
                'responses' => [self::json(['candidates' => [['content' => ['parts' => [['text' => 'Planning first.', 'thought' => true], ['text' => 'Berlin is sunny.']]], 'finishReason' => 'STOP']], 'usageMetadata' => $usage])],
                'call' => static fn (GoogleProvider $p): array => $p->chat([['role' => 'system', 'content' => 'You are terse.'], ['role' => 'user', 'content' => 'Weather in Berlin?']], ['model' => 'gemini-2.5-flash', 'temperature' => 0.2, 'max_tokens' => 256]),
            ],
            'chat.conversation_with_tools' => [
                'responses' => [self::json(['candidates' => [['content' => ['parts' => [['text' => 'Let me check.'], ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Berlin']]]]], 'finishReason' => 'STOP']], 'usageMetadata' => $usage])],
                'call' => static fn (GoogleProvider $p): array => $p->chat(self::conversation(), ['model' => 'gemini-2.5-flash', 'tools' => self::TOOLS, 'tool_choice' => 'auto']),
            ],
            'chat.structured_output' => [
                'responses' => [self::json(['candidates' => [['content' => ['parts' => [['text' => '{"topic":"weather"}']]], 'finishReason' => 'STOP']]])],
                'call' => static fn (GoogleProvider $p): array => $p->chat([['role' => 'user', 'content' => 'Sort this.']], ['model' => 'gemini-2.5-flash', 'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object', 'properties' => ['topic' => ['type' => 'string']], 'required' => ['topic']])]),
            ],
            'chat.finish_safety' => [
                'responses' => [self::json(['candidates' => [['content' => ['parts' => [['text' => 'I cannot help with that.']]], 'finishReason' => 'SAFETY', 'safetyRatings' => [['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'probability' => 'HIGH']]]]])],
                'call' => static fn (GoogleProvider $p): array => $p->chat([['role' => 'user', 'content' => 'Something unsafe']], ['model' => 'gemini-2.5-flash']),
            ],
            'chat.prompt_blocked' => [
                'responses' => [self::json(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']])],
                'call' => static fn (GoogleProvider $p): array => $p->chat([['role' => 'user', 'content' => 'Something prohibited']], ['model' => 'gemini-2.5-flash']),
            ],
            'chat.http_error' => [
                'responses' => [self::json(['error' => ['code' => 400, 'message' => 'API key not valid.', 'status' => 'INVALID_ARGUMENT']], 400)],
                'call' => static fn (GoogleProvider $p): array => $p->chat([['role' => 'user', 'content' => 'Hello']], ['model' => 'gemini-2.5-flash']),
            ],
            'chat.no_model' => [
                'call' => static fn (GoogleProvider $p): array => $p->chat([['role' => 'user', 'content' => 'Hello']]),
            ],
            'chat.no_key' => [
                'provider' => 'none',
                'call' => static fn (GoogleProvider $p): array => $p->chat([['role' => 'user', 'content' => 'Hello']], ['model' => 'gemini-2.5-flash']),
            ],
        ];
    }

    /**
     * @return array<string, array{provider?: string, responses?: list<MockResponse>, call: \Closure}>
     */
    private static function streamCases(): array
    {
        $usage = ['usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 7, 'cachedContentTokenCount' => 4]];
        $text = static function (string $text, ?string $finish = null, bool $thought = false): array {
            $candidate = ['content' => ['parts' => [$thought ? ['text' => $text, 'thought' => true] : ['text' => $text]]]];

            return ['candidates' => [null === $finish ? $candidate : $candidate + ['finishReason' => $finish]]];
        };

        return [
            'stream.text_reasoning_usage' => [
                'responses' => [self::sse($text('Thinking about the sky.', null, true), $text('Berlin is '), $text('sunny.', 'STOP') + $usage)],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent): array => $p->chatStream([['role' => 'user', 'content' => 'Weather in Berlin?']], $onEvent, ['model' => 'gemini-2.5-flash', 'reasoning_effort' => 'low']),
            ],
            'stream.tool_call' => [
                'responses' => [self::sse(['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Paris']]]]]]]], $text('', 'STOP') + $usage)],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent): array => $p->chatStream(self::conversation(), $onEvent, ['model' => 'gemini-2.5-flash', 'tools' => self::TOOLS, 'tool_choice' => 'required']),
            ],
            'stream.pro_thinking_off_structured_max_tokens' => [
                'responses' => [self::sse($text('{"topic":', 'MAX_TOKENS'))],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent): array => $p->chatStream([['role' => 'user', 'content' => 'Sort this.']], $onEvent, ['model' => 'gemini-2.5-pro', 'reasoning_effort' => 'off', 'max_tokens' => 64, 'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object'])]),
            ],
            'stream.thinking_budget_passthrough' => [
                'responses' => [self::sse($text('Done.', 'STOP'))],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent): array => $p->chatStream([['role' => 'user', 'content' => 'Think hard.']], $onEvent, ['model' => 'gemini-2.5-pro', 'thinking_budget' => 2048, 'reasoning_effort' => 'off']),
            ],
            'stream.legacy_reasoning_flag' => [
                'responses' => [self::sse($text('Done.', 'END_TURN'))],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent): array => $p->chatStream([['role' => 'user', 'content' => 'Think.']], $onEvent, ['model' => 'gemini-2.5-flash', 'reasoning' => true, 'temperature' => 1.0, 'top_p' => 0.5, 'top_k' => 10]),
            ],
            'stream.retry_once_on_503' => [
                'responses' => [self::json(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503), self::sse($text('Back again.', 'STOP'))],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent): array => $p->chatStream([['role' => 'user', 'content' => 'Hello']], $onEvent, ['model' => 'gemini-2.5-flash']),
            ],
            'stream.overloaded' => [
                'responses' => [self::json(['error' => ['code' => 503, 'message' => 'The model is overloaded.', 'status' => 'UNAVAILABLE']], 503), self::json(['error' => ['code' => 503, 'message' => 'The model is overloaded.', 'status' => 'UNAVAILABLE']], 503)],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent): array => $p->chatStream([['role' => 'user', 'content' => 'Hello']], $onEvent, ['model' => 'gemini-2.5-flash']),
            ],
            'stream.http_error' => [
                'responses' => [self::json(['error' => ['code' => 429, 'message' => 'Quota exceeded.', 'status' => 'RESOURCE_EXHAUSTED']], 429)],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent): array => $p->chatStream([['role' => 'user', 'content' => 'Hello']], $onEvent, ['model' => 'gemini-2.5-flash']),
            ],
            'stream.blocked_mid_stream' => [
                'responses' => [self::sse($text('Here is'), $text('', 'RECITATION'))],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent): array => $p->chatStream([['role' => 'user', 'content' => 'Recite a song.']], $onEvent, ['model' => 'gemini-2.5-flash']),
            ],
            'stream.no_key' => [
                'provider' => 'none',
                'call' => static fn (GoogleProvider $p, \Closure $onEvent): array => $p->chatStream([['role' => 'user', 'content' => 'Hello']], $onEvent, ['model' => 'gemini-2.5-flash']),
            ],
        ];
    }

    /**
     * @return array<string, array{provider?: string, responses?: list<MockResponse>, call: \Closure}>
     */
    private static function imageCases(): array
    {
        $inline = ['candidates' => [['content' => ['parts' => [['text' => 'Here you go.'], ['inlineData' => ['mimeType' => 'image/png', 'data' => self::PIXEL]]]], 'finishReason' => 'STOP']]];
        $imagen = ['predictions' => [['bytesBase64Encoded' => self::PIXEL, 'mimeType' => 'image/png'], ['bytesBase64Encoded' => self::PIXEL]]];

        return [
            'image.gemini_default_model' => [
                'responses' => [self::json($inline)],
                'call' => static fn (GoogleProvider $p): array => $p->generateImage('A red bicycle', ['aspect_ratio' => '16:9']),
            ],
            'image.gemini_text_only' => [
                'responses' => [self::json(['candidates' => [['content' => ['parts' => [['text' => 'The photo shows a cat.']]], 'finishReason' => 'STOP']]])],
                'call' => static fn (GoogleProvider $p): array => $p->generateImage('What is in the photo?', ['model' => 'gemini-2.5-flash-image']),
            ],
            'image.gemini_pic2pic' => [
                'responses' => [self::json(['candidates' => [['content' => ['parts' => [['inline_data' => ['mime_type' => 'image/webp', 'data' => self::PIXEL]]]], 'finishReason' => 'STOP']]])],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent, string $uploads): array => $p->generateImage('Make it blue', ['model' => 'gemini-2.5-flash-image', 'images' => [$uploads.'/photo.png', $uploads.'/car.png'], 'aspect_ratio' => '1:1']),
            ],
            'image.imagen_gemini_api' => [
                'responses' => [self::json($imagen)],
                'call' => static fn (GoogleProvider $p): array => $p->generateImage('A lighthouse', ['model' => 'imagen-4.0-generate-001', 'n' => 2]),
            ],
            'image.imagen_filtered' => [
                'responses' => [self::json(['predictions' => [['raiFilteredReason' => 'The prompt names a real person.']]])],
                'call' => static fn (GoogleProvider $p): array => $p->generateImage('A famous actor', ['model' => 'imagen-4.0-generate-001']),
            ],
            'image.imagen_vertex' => [
                'provider' => 'vertex',
                'responses' => [self::json($imagen)],
                'call' => static fn (GoogleProvider $p): array => $p->generateImage('A lighthouse', ['model' => 'imagen-4.0-generate-001', 'aspect_ratio' => '4:3']),
            ],
            'image.explicit_imagen_api' => [
                'responses' => [self::json(['predictions' => []])],
                'call' => static fn (GoogleProvider $p): array => $p->generateImage('A lighthouse', ['model' => 'gemini-2.5-flash-image', 'modelConfig' => ['api' => 'imagen']]),
            ],
            'image.http_error' => [
                'responses' => [self::json(['error' => ['code' => 404, 'message' => 'Model not found.']], 404)],
                'call' => static fn (GoogleProvider $p): array => $p->generateImage('A red bicycle', ['model' => 'gemini-2.5-flash-image']),
            ],
            'image.no_key' => [
                'provider' => 'none',
                'call' => static fn (GoogleProvider $p): array => $p->generateImage('A red bicycle'),
            ],
            'image.variations' => [
                'call' => static fn (GoogleProvider $p): array => $p->createVariations('photo.png', 2),
            ],
            'image.edit' => [
                'responses' => [self::json(['candidates' => [['content' => ['parts' => [['inline_data' => ['mime_type' => 'image/png', 'data' => self::PIXEL]]]]]]])],
                'call' => static fn (GoogleProvider $p): string => $p->editImage('photo.png', 'mask.png', 'Add a hat'),
            ],
            'image.edit_no_image' => [
                'responses' => [self::json(['candidates' => [['content' => ['parts' => [['text' => 'No.']]]]]])],
                'call' => static fn (GoogleProvider $p): string => $p->editImage('/photo.png', 'mask.png', 'Add a hat'),
            ],
        ];
    }

    /**
     * @return array<string, array{provider?: string, responses?: list<MockResponse>, call: \Closure}>
     */
    private static function videoCases(): array
    {
        $veoDone = static fn (array $response): array => ['name' => 'models/veo-3.1-generate-preview/operations/op-1', 'done' => true, 'response' => $response];

        return [
            'video.veo_start' => [
                'responses' => [self::json(['name' => 'models/veo-3.1-generate-preview/operations/op-1'])],
                'call' => static fn (GoogleProvider $p): array => $p->startVideoOperation('A drone shot over a lake', ['model' => 'veo-3.1-generate-preview', 'duration' => 5, 'aspect_ratio' => '9:16', 'resolution' => '4K', 'modelConfig' => ['allowed_resolutions' => ['720p', '1080p', '4K']]]),
            ],
            'video.veo_start_disallowed_resolution' => [
                'responses' => [self::json(['name' => 'models/veo-3.1-generate-preview/operations/op-2'])],
                'call' => static fn (GoogleProvider $p): array => $p->startVideoOperation('A drone shot', ['duration' => '7', 'resolution' => '4K', 'modelConfig' => ['allowed_resolutions' => ['720p', '1080p'], 'default_resolution' => '720p']]),
            ],
            'video.veo_start_error' => [
                'responses' => [self::json(['error' => ['code' => 400, 'message' => 'Unsupported aspect ratio.']], 400)],
                'call' => static fn (GoogleProvider $p): array => $p->startVideoOperation('A drone shot', ['aspect_ratio' => '1:1']),
            ],
            'video.omni_start' => [
                'responses' => [self::json(['id' => 'int-77', 'status' => 'in_progress'], 201)],
                'call' => static fn (GoogleProvider $p): array => $p->startVideoOperation('A cat on a skateboard', ['model' => 'gemini-omni-flash', 'duration' => 20]),
            ],
            'video.veo_poll_running' => [
                'responses' => [self::json(['name' => 'models/veo-3.1-generate-preview/operations/op-1', 'done' => false])],
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('models/veo-3.1-generate-preview/operations/op-1'),
            ],
            'video.veo_poll_done' => [
                'responses' => [self::json($veoDone(['generateVideoResponse' => ['generatedSamples' => [['video' => ['uri' => 'https://generativelanguage.googleapis.com/v1beta/files/vid-1:download?alt=media']]]]]))],
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('models/veo-3.1-generate-preview/operations/op-1'),
            ],
            'video.veo_poll_failed' => [
                'responses' => [self::json(['done' => true, 'error' => ['code' => 3, 'message' => 'Invalid argument.']])],
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('models/veo-3.1-generate-preview/operations/op-1'),
            ],
            'video.veo_poll_safety_error' => [
                'responses' => [self::json(['done' => true, 'error' => ['code' => 3, 'message' => 'Blocked by safety filters.']])],
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('models/veo-3.1-generate-preview/operations/op-1'),
            ],
            'video.veo_poll_rai_filtered' => [
                'responses' => [self::json($veoDone(['generateVideoResponse' => ['raiMediaFilteredCount' => 1, 'raiMediaFilteredReasons' => ["We can't create videos with real people's likenesses."]]]))],
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('models/veo-3.1-generate-preview/operations/op-1'),
            ],
            'video.veo_poll_no_uri' => [
                'responses' => [self::json($veoDone(['generateVideoResponse' => []]))],
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('models/veo-3.1-generate-preview/operations/op-1'),
            ],
            'video.omni_poll_running' => [
                'responses' => [self::json(['id' => 'int-77', 'status' => 'IN_PROGRESS'])],
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('interactions/int-77'),
            ],
            'video.omni_poll_done' => [
                'responses' => [self::json(['id' => 'int-77', 'status' => 'completed', 'steps' => [
                    ['type' => 'thought', 'content' => [['type' => 'video', 'uri' => 'https://example.test/draft.mp4']]],
                    ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'Done'], ['type' => 'video', 'uri' => 'https://example.test/final.mp4']]],
                ]])],
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('interactions/int-77'),
            ],
            'video.omni_poll_inline' => [
                'responses' => [self::json(['id' => 'int-77', 'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'video', 'data' => base64_encode('omni-bytes')]]]]])],
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('interactions/int-77'),
            ],
            'video.omni_poll_failed' => [
                'responses' => [self::json(['id' => 'int-77', 'status' => 'failed', 'error' => ['message' => 'Quota exhausted.']])],
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('interactions/int-77'),
            ],
            'video.cancel_veo' => [
                'responses' => [self::json([])],
                'call' => static function (GoogleProvider $p): null {
                    $p->cancelVideoOperation('models/veo-3.1-generate-preview/operations/op-1');

                    return null;
                },
            ],
            'video.cancel_omni_failed' => [
                'responses' => [self::json(['error' => 'gone'], 500)],
                'call' => static function (GoogleProvider $p): null {
                    $p->cancelVideoOperation('interactions/int-77');

                    return null;
                },
            ],
            'video.download' => [
                'responses' => [new MockResponse('mp4-bytes', ['response_headers' => ['content-type' => 'video/mp4']])],
                'call' => static fn (GoogleProvider $p): string => $p->downloadVideoContent('https://generativelanguage.googleapis.com/v1beta/files/vid-1:download?alt=media'),
            ],
            'video.download_raw_inline' => [
                'call' => static fn (GoogleProvider $p): string => $p->downloadVideoRaw('data:video/mp4;base64,'.base64_encode('inline-bytes')),
            ],
            'video.generate_cancelled' => [
                'responses' => [self::json(['name' => 'models/veo-3.1-generate-preview/operations/op-9']), self::json([])],
                'call' => static fn (GoogleProvider $p): array => $p->generateVideo('A drone shot', ['model' => 'veo-3.1-generate-preview', 'cancel_check' => static fn (): bool => true]),
            ],
            'video.no_key' => [
                'provider' => 'none',
                'call' => static fn (GoogleProvider $p): array => $p->pollVideoOperationOnce('interactions/int-77'),
            ],
        ];
    }

    /**
     * @return array<string, array{provider?: string, responses?: list<MockResponse>, call: \Closure}>
     */
    private static function speechCases(): array
    {
        $transcript = static fn (array $usage = []): array => ['candidates' => [['content' => ['parts' => [['text' => 'Hallo '], ['text' => 'Welt']]]]]] + $usage;
        $audio = static fn (string $key, string $mime, string $bytes): array => ['candidates' => [['content' => ['parts' => [['text' => 'Audio follows.'], [$key => ['inlineData' === $key ? 'mimeType' : 'mime_type' => $mime, 'data' => base64_encode($bytes)]]]]]]];

        return [
            'speech.transcribe_audio_tokens' => [
                'responses' => [self::json($transcript(['usageMetadata' => ['promptTokensDetails' => [['modality' => 'TEXT', 'tokenCount' => 3], ['modality' => 'AUDIO', 'tokenCount' => 50]]]]))],
                'call' => static fn (GoogleProvider $p): array => $p->transcribe('voice.wav', ['language' => 'de']),
            ],
            'speech.transcribe_wav_duration' => [
                'responses' => [self::json($transcript())],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent, string $uploads): array => $p->transcribe($uploads.'/voice.wav', ['model' => 'whisper-1']),
            ],
            'speech.transcribe_mp3_without_usage' => [
                'responses' => [self::json($transcript())],
                'call' => static fn (GoogleProvider $p): array => $p->transcribe('voice.mp3'),
            ],
            'speech.transcribe_missing_file' => [
                'call' => static fn (GoogleProvider $p): array => $p->transcribe('missing.wav'),
            ],
            'speech.transcribe_http_error' => [
                'responses' => [self::json(['error' => ['code' => 500, 'message' => 'Internal error.']], 500)],
                'call' => static fn (GoogleProvider $p): array => $p->transcribe('voice.wav'),
            ],
            'speech.translate' => [
                'call' => static fn (GoogleProvider $p): string => $p->translateAudio('voice.wav', 'en'),
            ],
            'speech.synthesize_pcm' => [
                'responses' => [self::json($audio('inlineData', 'audio/L16;codec=pcm;rate=16000;channels=1', str_repeat("\x02\x00", 160)))],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent, string $uploads): array => self::audioFile($uploads, $p->synthesize('Hallo Welt', ['voice' => 'Puck', 'model' => 'gemini-3.1-flash-tts-preview'])),
            ],
            'speech.synthesize_mp3_snake_case' => [
                'responses' => [self::json($audio('inline_data', 'audio/mp3', 'ID3-mp3-bytes'))],
                'call' => static fn (GoogleProvider $p, \Closure $onEvent, string $uploads): array => self::audioFile($uploads, $p->synthesize('Hallo Welt', ['model' => 'tts-1'])),
            ],
            'speech.synthesize_no_audio' => [
                'responses' => [self::json(['candidates' => [['content' => ['parts' => [['text' => 'No audio.']]]]]])],
                'call' => static fn (GoogleProvider $p): string => $p->synthesize('Hallo Welt'),
            ],
            'speech.synthesize_http_error' => [
                'responses' => [self::json(['error' => ['code' => 429, 'message' => 'Rate limited.']], 429)],
                'call' => static fn (GoogleProvider $p): string => $p->synthesize('Hallo Welt'),
            ],
            'speech.synthesize_stream' => [
                'responses' => [self::json($audio('inlineData', 'audio/pcm', str_repeat("\x03\x00", 6000)))],
                'call' => static function (GoogleProvider $p, \Closure $onEvent, string $uploads): array {
                    $before = glob($uploads.'/tts_*') ?: [];
                    $chunks = iterator_to_array($p->synthesizeStream('Hallo Welt'), false);

                    return ['chunks' => array_map('strlen', $chunks), 'sha1' => sha1(implode('', $chunks)), 'files_left' => \count(array_diff(glob($uploads.'/tts_*') ?: [], $before))];
                },
            ],
            'speech.no_key' => [
                'provider' => 'none',
                'call' => static fn (GoogleProvider $p): string => $p->synthesize('Hallo Welt'),
            ],
        ];
    }

    /**
     * @return array<string, array{provider?: string, responses?: list<MockResponse>, call: \Closure}>
     */
    private static function visionCases(): array
    {
        $answer = static fn (string $text): MockResponse => self::json(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);

        return [
            'vision.analyze' => [
                'responses' => [$answer('A single pixel.')],
                'call' => static fn (GoogleProvider $p): string => $p->analyzeImage('photo.png', 'What is this?', ['model' => 'gemini-2.5-flash', 'max_tokens' => 1024]),
            ],
            'vision.explain_default_prompt' => [
                'responses' => [$answer('A tiny image.')],
                'call' => static fn (GoogleProvider $p): string => $p->explainImage('/photo.png'),
            ],
            'vision.extract_text' => [
                'responses' => [$answer('')],
                'call' => static fn (GoogleProvider $p): string => $p->extractTextFromImage('photo.png'),
            ],
            'vision.compare' => [
                'responses' => [$answer('Both are single pixels.')],
                'call' => static fn (GoogleProvider $p): array => $p->compareImages('photo.png', 'car.png'),
            ],
            'vision.missing_file' => [
                'call' => static fn (GoogleProvider $p): string => $p->analyzeImage('missing.png', 'What is this?'),
            ],
            'vision.http_error' => [
                'responses' => [self::json(['error' => ['code' => 403, 'message' => 'Permission denied.']], 403)],
                'call' => static fn (GoogleProvider $p): array => $p->compareImages('photo.png', 'car.png'),
            ],
        ];
    }

    /**
     * @return array<string, array{provider?: string, responses?: list<MockResponse>, call: \Closure}>
     */
    private static function metaCases(): array
    {
        $describe = static fn (GoogleProvider $p): array => [
            'name' => $p->getName(),
            'display_name' => $p->getDisplayName(),
            'description' => $p->getDescription(),
            'capabilities' => $p->getCapabilities(),
            'default_models' => $p->getDefaultModels(),
            'required_env' => $p->getRequiredEnvVars(),
            'status' => $p->getStatus(),
            'available' => $p->isAvailable(),
            'tool_calling' => ['gemini-2.5-flash' => $p->supportsToolCalling('gemini-2.5-flash'), 'not-in-catalog' => $p->supportsToolCalling('not-in-catalog')],
            'stream_content_type' => $p->getStreamContentType(),
            'streams_speech' => $p->supportsStreaming(),
            'voices' => $p->getVoices(),
        ];

        return [
            'meta.with_key' => ['call' => $describe],
            'meta.without_key' => ['provider' => 'none', 'call' => $describe],
        ];
    }

    /**
     * Every message shape the Gemini conversion handles: a leading model
     * turn, a system turn beside a user turn, a tool call and its result, an
     * image part, blank parts and a blank turn.
     *
     * @return list<array<string, mixed>>
     */
    private static function conversation(): array
    {
        return [
            ['role' => 'assistant', 'content' => 'Welcome back.'],
            ['role' => 'system', 'content' => 'You are terse.'],
            ['role' => 'user', 'content' => 'What is the weather in Berlin?'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_w1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_w1', 'content' => ['temp' => 18]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'And this photo?'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.self::PIXEL]], ['type' => 'text', 'text' => '   ']]],
            ['role' => 'assistant', 'content' => '   '],
            ['role' => 'user', 'content' => 'Thanks.'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * @param array<string, mixed> ...$events
     */
    private static function sse(array ...$events): MockResponse
    {
        return new MockResponse(array_map(static fn (array $event): string => 'data: '.json_encode($event)."\r\n\r\n", $events), ['response_headers' => ['content-type' => 'text/event-stream']]);
    }

    private static function wav(string $pcm, int $sampleRate): string
    {
        return 'RIFF'.pack('V', 36 + \strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16).'data'.pack('V', \strlen($pcm)).$pcm;
    }

    /**
     * @return array<string, mixed>
     */
    private static function audioFile(string $uploads, string $filename): array
    {
        $bytes = (string) file_get_contents($uploads.'/'.$filename);

        return ['filename' => $filename, 'bytes' => \strlen($bytes), 'head' => bin2hex(substr($bytes, 0, 12)), 'sha1' => sha1($bytes)];
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
     * Tool-call ids are random and audio file names unique per call; the
     * upload directory is temporary.
     *
     * @param array<string, mixed> $outcome
     *
     * @return array<string, mixed>
     */
    private function normalize(array $outcome): array
    {
        array_walk_recursive($outcome, function (mixed &$value): void {
            if (\is_string($value)) {
                $value = preg_replace(['/call_[0-9a-f]{12}/', '/tts_[0-9a-f]{13}\./'], ['call_<random>', 'tts_<unique>.'], str_replace($this->uploadDir, '<uploads>', $value));
            }
        });

        return $outcome;
    }
}
