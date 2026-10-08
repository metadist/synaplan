<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\OpenAIProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Issue #2404: image edits through the Responses API sent the image tool
 * without the requested `quality`, while billing recorded `high` at
 * 1024x1024. These cases pin the body that
 * {@see OpenAIProvider::generateImageWithResponsesApi()} sends.
 */
final class OpenAIProviderImageEditRequestTest extends TestCase
{
    private const PHOTO = 'data:image/jpeg;base64,/9j/4AAQ';

    /**
     * @return iterable<string, array{string}>
     */
    public static function editModels(): iterable
    {
        yield 'Flare' => ['gpt-image-2.5-flare'];
        yield 'Sunburst' => ['gpt-image-2.5-sunburst'];
    }

    #[DataProvider('editModels')]
    public function testAnEditSendsTheRequestedQualityAndLeavesSizeAndFidelityToTheModel(string $model): void
    {
        $body = $this->build('Add muntins', $model, [self::PHOTO], ['quality' => 'high', 'size' => '1024x1024']);

        $tool = $body['tools'][0];
        self::assertSame('image_generation', $tool['type']);
        self::assertSame($model, $tool['model']);
        self::assertSame('high', $tool['quality'] ?? null);
        self::assertArrayNotHasKey('size', $tool, 'An edit must not force a square output.');
        self::assertArrayNotHasKey('input_fidelity', $tool);
    }

    public function testNoModelGetsInputFidelity(): void
    {
        foreach (['gpt-image-1', 'gpt-image-1.5', 'gpt-image-2.5-flare'] as $model) {
            $tool = $this->build('Add muntins', $model, [self::PHOTO], ['quality' => 'high'])['tools'][0];

            self::assertArrayNotHasKey('input_fidelity', $tool, $model);
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function qualities(): iterable
    {
        yield 'standard maps to medium' => ['gpt-image-2.5-flare', 'standard', 'medium'];
        yield 'hd maps to high' => ['gpt-image-2.5-flare', 'hd', 'high'];
        yield 'case does not matter' => ['gpt-image-2.5-flare', 'LOW', 'low'];
        yield 'a 2.5-only tier passes on 2.5' => ['gpt-image-2.5-flare', 'xhigh', 'xhigh'];
        yield 'a 2.5-only tier is clamped on 1.5' => ['gpt-image-1.5', 'xhigh', 'high'];
    }

    #[DataProvider('qualities')]
    public function testQualityIsNormalisedLikeTheImagesApi(string $model, string $requested, string $sent): void
    {
        $tool = $this->build('Add muntins', $model, [self::PHOTO], ['quality' => $requested])['tools'][0];

        self::assertSame($sent, $tool['quality'] ?? null);
    }

    public function testNoRequestedQualitySendsNone(): void
    {
        $tool = $this->build('Add muntins', 'gpt-image-2.5-flare', [self::PHOTO], [])['tools'][0];

        self::assertArrayNotHasKey('quality', $tool);
    }

    public function testEveryImageIsSentAfterThePrompt(): void
    {
        $body = $this->build('Merge them', 'gpt-image-2.5-flare', [self::PHOTO, 'data:image/png;base64,iVBOR'], []);

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
    private function build(string $prompt, string $model, array $images, array $options): array
    {
        $provider = new OpenAIProvider(new NullLogger(), new MockHttpClient());
        $method = new \ReflectionMethod(OpenAIProvider::class, 'buildImageEditRequest');

        /** @var array{model: string, input: list<array<string, mixed>>, tools: list<array<string, mixed>>} $body */
        $body = $method->invoke($provider, $prompt, $model, $images, $options);

        return $body;
    }
}
