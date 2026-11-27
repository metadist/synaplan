<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\OpenAI;

use App\AI\Provider\OpenAI\OpenAIImageGeneration;
use App\Tests\Characterization\Support\OpenAIFixtures;
use OpenAI\Responses\Images\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAIImageGenerationTest extends TestCase
{
    use OpenAIApiDouble;

    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    public function testDallEGoesThroughTheSdkWithItsDefaults(): void
    {
        $images = new OpenAIImageGeneration($this->api([OpenAIFixtures::json(CreateResponse::fake(['data' => [['url' => 'https://example.test/a.png', 'revised_prompt' => 'A bike', 'b64_json' => null]]], null, OverrideStrategy::Replace)->toArray())]), new NullLogger());

        self::assertSame('https://example.test/a.png', $images->generateImage('A bike')[0]['url']);
        self::assertSame(['model' => 'dall-e-3', 'prompt' => 'A bike', 'n' => 1, 'size' => '1024x1024', 'quality' => 'standard', 'style' => 'vivid'], $this->sdk->requests()[0]['body']);
    }

    public function testGptImageGoesThroughCurlWithItsQualityMapped(): void
    {
        $images = new OpenAIImageGeneration($this->api(curlAnswers: [[(string) json_encode(['data' => [['b64_json' => self::PIXEL]]]), 200, '']]), new NullLogger());

        $result = $images->generateImage('A bike', ['model' => 'gpt-image-1', 'quality' => 'hd']);

        self::assertSame('data:image/png;base64,'.self::PIXEL, $result[0]['url']);
        self::assertSame(['https://api.openai.com/v1/images/generations', 'high', 120], [$this->curlRequests[0]['url'], $this->curlRequests[0]['body']['quality'], $this->curlRequests[0]['timeout']]);
    }

    public function testAttachedImagesGoToTheResponsesApiWithTheImageTool(): void
    {
        $photo = sys_get_temp_dir().'/openai-image-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($photo, base64_decode(self::PIXEL));
        $images = new OpenAIImageGeneration($this->api(httpResponses: [new MockResponse((string) json_encode(['output' => [['type' => 'image_generation_call', 'result' => self::PIXEL, 'size' => '1024x1536']]]))]), new NullLogger());

        try {
            $result = $images->generateImage('Make it blue', ['model' => 'gpt-image-1', 'images' => [$photo]]);
        } finally {
            unlink($photo);
        }

        $body = $this->http->requests()[0]['body'];
        self::assertSame('1024x1536', $result[0]['size']);
        self::assertSame(['gpt-5.6-terra', ['type' => 'image_generation', 'model' => 'gpt-image-1']], [$body['model'], $body['tools'][0]]);
        self::assertSame('input_image', $body['input'][0]['content'][1]['type']);
    }

    public function testTheImagesApiUsageRidesOnTheFirstImage(): void
    {
        $usage = ['input_tokens' => 12, 'input_tokens_details' => ['text_tokens' => 12, 'image_tokens' => 0], 'output_tokens' => 439, 'total_tokens' => 451];
        $images = new OpenAIImageGeneration($this->api(curlAnswers: [[(string) json_encode(['data' => [['b64_json' => self::PIXEL], ['b64_json' => self::PIXEL]], 'usage' => $usage]), 200, '']]), new NullLogger());

        $result = $images->generateImage('A bike', ['model' => 'gpt-image-2.6-flare', 'n' => 2]);

        self::assertSame(['text_in' => 12, 'out' => 439], $result[0]['token_usage']);
        self::assertArrayNotHasKey('token_usage', $result[1]);
    }

    public function testAnAnswerWithoutUsageCarriesNoTokens(): void
    {
        $images = new OpenAIImageGeneration($this->api(curlAnswers: [[(string) json_encode(['data' => [['b64_json' => self::PIXEL]]]), 200, '']]), new NullLogger());

        self::assertArrayNotHasKey('token_usage', $images->generateImage('A bike', ['model' => 'gpt-image-2.6-flare'])[0]);
    }

    public function testTheResponsesApiReportsTheImageToolAndTheModelThatCalledIt(): void
    {
        $photo = sys_get_temp_dir().'/openai-image-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($photo, base64_decode(self::PIXEL));
        $answer = [
            'output' => [['type' => 'image_generation_call', 'result' => self::PIXEL]],
            'usage' => ['input_tokens' => 300, 'input_tokens_details' => ['cached_tokens' => 100], 'output_tokens' => 40],
            'tool_usage' => ['image_gen' => [
                'input_tokens' => 1080,
                'input_tokens_details' => ['text_tokens' => 80, 'image_tokens' => 1000],
                'cached_tokens_details' => ['cached_text_tokens' => 0, 'cached_image_tokens' => 500],
                'output_tokens' => 1756,
            ]],
        ];
        $images = new OpenAIImageGeneration($this->api(httpResponses: [new MockResponse((string) json_encode($answer))]), new NullLogger());

        try {
            $result = $images->generateImage('Make it blue', ['model' => 'gpt-image-2.6-flare', 'images' => [$photo]]);
        } finally {
            unlink($photo);
        }

        self::assertSame([
            'text_in' => 80,
            'image_in' => 1000,
            'cached_image_in' => 500,
            'out' => 1756,
            'wrapper_model' => 'gpt-5.6-terra',
            'wrapper_in' => 300,
            'wrapper_cached_in' => 100,
            'wrapper_out' => 40,
        ], $result[0]['token_usage']);
    }
}
