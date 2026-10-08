<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\OpenAIProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Issue #2404: an edit with gpt-image-1 / gpt-image-1.5 redrew the attached
 * photo as a square because the image tool carried neither `input_fidelity`
 * nor the requested `quality`. These cases pin the Responses API body that
 * {@see OpenAIProvider::generateImageWithResponsesApi()} sends.
 */
final class OpenAIProviderImageEditRequestTest extends TestCase
{
    private const PHOTO = 'data:image/jpeg;base64,/9j/4AAQ';

    public function testGptImage15EditKeepsThePhotoAtTheRequestedQuality(): void
    {
        $body = $this->build('Add muntins', [self::PHOTO], ['model' => 'gpt-image-1.5', 'quality' => 'high', 'size' => '1024x1024']);

        $tool = $body['tools'][0];
        self::assertSame('image_generation', $tool['type']);
        self::assertSame('gpt-image-1.5', $tool['model']);
        self::assertSame('high', $tool['input_fidelity'] ?? null);
        self::assertSame('high', $tool['quality'] ?? null);
        self::assertArrayNotHasKey('size', $tool, 'An edit must not force a square output.');
    }

    public function testGptImage1EditAsksForHighInputFidelity(): void
    {
        $tool = $this->build('Add muntins', [self::PHOTO], ['model' => 'gpt-image-1'])['tools'][0];

        self::assertSame('high', $tool['input_fidelity'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gptImage2Models(): iterable
    {
        yield 'Flare' => ['gpt-image-2.5-flare'];
        yield 'Sunburst' => ['gpt-image-2.5-sunburst'];
    }

    #[DataProvider('gptImage2Models')]
    public function testGptImage2ModelsNeverGetInputFidelity(string $model): void
    {
        $tool = $this->build('Add muntins', [self::PHOTO], ['model' => $model, 'quality' => 'high'])['tools'][0];

        self::assertArrayNotHasKey('input_fidelity', $tool);
        self::assertSame($model, $tool['model']);
        self::assertSame('high', $tool['quality'] ?? null);
    }

    public function testNoInputImagesMeansNoInputFidelity(): void
    {
        $tool = $this->build('A lighthouse', [], ['model' => 'gpt-image-1.5'])['tools'][0];

        self::assertArrayNotHasKey('input_fidelity', $tool);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function qualities(): iterable
    {
        yield 'standard maps to medium' => ['gpt-image-1.5', 'standard', 'medium'];
        yield 'hd maps to high' => ['gpt-image-1.5', 'hd', 'high'];
        yield 'case does not matter' => ['gpt-image-1.5', 'LOW', 'low'];
        yield 'a 2.5-only tier is clamped on 1.5' => ['gpt-image-1.5', 'xhigh', 'high'];
        yield 'a 2.5-only tier passes on 2.5' => ['gpt-image-2.5-flare', 'xhigh', 'xhigh'];
    }

    #[DataProvider('qualities')]
    public function testQualityIsNormalisedLikeTheImagesApi(string $model, string $requested, string $sent): void
    {
        $tool = $this->build('Add muntins', [self::PHOTO], ['model' => $model, 'quality' => $requested])['tools'][0];

        self::assertSame($sent, $tool['quality'] ?? null);
    }

    public function testNoRequestedQualitySendsNone(): void
    {
        $tool = $this->build('Add muntins', [self::PHOTO], ['model' => 'gpt-image-1.5'])['tools'][0];

        self::assertArrayNotHasKey('quality', $tool);
    }

    public function testEveryImageIsSentAfterThePrompt(): void
    {
        $body = $this->build('Merge them', [self::PHOTO, 'data:image/png;base64,iVBOR'], ['model' => 'gpt-image-1.5']);

        self::assertSame('gpt-5.6-terra', $body['model']);
        self::assertSame([
            ['type' => 'input_text', 'text' => 'Merge them'],
            ['type' => 'input_image', 'image_url' => self::PHOTO],
            ['type' => 'input_image', 'image_url' => 'data:image/png;base64,iVBOR'],
        ], $body['input'][0]['content']);
        self::assertSame('user', $body['input'][0]['role']);
    }

    /**
     * @param list<string>         $images
     * @param array<string, mixed> $options
     *
     * @return array{model: string, input: list<array<string, mixed>>, tools: list<array<string, mixed>>}
     */
    private function build(string $prompt, array $images, array $options): array
    {
        $provider = new OpenAIProvider(new NullLogger(), new MockHttpClient());
        $method = new \ReflectionMethod(OpenAIProvider::class, 'buildImageEditRequest');

        /** @var array{model: string, input: list<array<string, mixed>>, tools: list<array<string, mixed>>} $body */
        $body = $method->invoke($provider, $prompt, $images, $options);

        return $body;
    }
}
