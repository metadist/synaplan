<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\OpenAI;

use App\AI\Exception\ProviderException;
use App\AI\Provider\OpenAI\OpenAIApiClient;
use App\AI\Provider\OpenAI\OpenAIResponses;
use App\AI\Provider\OpenAI\OpenAIVision;
use App\Tests\Characterization\Support\OpenAIFixtures;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OpenAIVisionTest extends TestCase
{
    use OpenAIApiDouble;

    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/openai-vision-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir);
        file_put_contents($this->uploadDir.'/photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg=='));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->uploadDir.'/*') ?: []);
        rmdir($this->uploadDir);
    }

    public function testAChatModelAnswersOnChatCompletionsAndAReasoningModelOnResponses(): void
    {
        $chatAnswer = OpenAIFixtures::json(CreateResponse::fake(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'A pixel.', 'function_call' => null, 'tool_calls' => []], 'logprobs' => null, 'finish_reason' => 'stop']]])->toArray());
        $vision = $this->vision($this->api([OpenAIFixtures::model('gpt-4o'), $chatAnswer, OpenAIFixtures::model('gpt-5.5'), OpenAIFixtures::response([OpenAIFixtures::message('INVOICE 42')])]));

        self::assertSame(['A pixel.', 'INVOICE 42'], [$vision->analyzeImage('photo.png', 'What is this?'), $vision->analyzeImage('photo.png', 'Read it.', ['model' => 'gpt-5.5'])]);
        self::assertSame(['GET /v1/models/gpt-4o', 'POST /v1/chat/completions', 'GET /v1/models/gpt-5.5', 'POST /v1/responses'], $this->sdkCalls());
    }

    public function testAMissingImageIsRefusedWithoutARequest(): void
    {
        $vision = $this->vision($this->api());

        try {
            $vision->analyzeImage('missing.png', 'What is this?');
            self::fail('A missing image must be refused.');
        } catch (ProviderException $e) {
            self::assertSame('OpenAI vision error: Image file not found: missing.png', $e->getMessage());
        }
        self::assertSame([], $this->sdkCalls());
    }

    private function vision(OpenAIApiClient $api): OpenAIVision
    {
        return new OpenAIVision($api, new OpenAIResponses($api, new NullLogger()), new NullLogger(), $this->uploadDir, false);
    }
}
