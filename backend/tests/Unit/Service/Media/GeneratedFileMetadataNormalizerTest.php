<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\GeneratedFileMetadataNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see GeneratedFileMetadataNormalizer::normalize()}.
 *
 * Issue #626: media generated via the email / public widget / WhatsApp
 * channels used to land on the outgoing message row without a usable
 * `file path` and `file type`, so the web chat history endpoint only
 * surfaced the textual description and not the actual image/video/audio
 * player. The normaliser canonicalises whatever shape the
 * MediaGenerationHandler returns into `{path, type}`, or `null` when the
 * payload would yield a broken file reference (and the caller must keep
 * the legacy `file=0` flag).
 *
 * Originally lived as a `private` method duplicated in two controllers;
 * extracted into this service after the PR #947 review.
 */
final class GeneratedFileMetadataNormalizerTest extends TestCase
{
    private GeneratedFileMetadataNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new GeneratedFileMetadataNormalizer();
    }

    public function testNormalisesValidVideoFileMetadata(): void
    {
        $result = $this->normalizer->normalize([
            'path' => '/api/v1/files/uploads/13/000/00013/2026/05/42_google_1700000000.mp4',
            'type' => 'video',
        ]);

        self::assertSame([
            'path' => '/api/v1/files/uploads/13/000/00013/2026/05/42_google_1700000000.mp4',
            'type' => 'video',
        ], $result);
    }

    public function testNormalisesValidImageFileMetadata(): void
    {
        $result = $this->normalizer->normalize([
            'path' => '/api/v1/files/uploads/27/000/00027/2026/05/100_openai_1700000000.png',
            'type' => 'image',
        ]);

        self::assertSame([
            'path' => '/api/v1/files/uploads/27/000/00027/2026/05/100_openai_1700000000.png',
            'type' => 'image',
        ], $result);
    }

    public function testReturnsNullWhenMetadataMissing(): void
    {
        self::assertNull($this->normalizer->normalize(null));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonArrayInputProvider(): iterable
    {
        yield 'string' => ['not-an-array'];
        yield 'integer' => [42];
        yield 'boolean' => [true];
        yield 'float' => [3.14];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('nonArrayInputProvider')]
    public function testReturnsNullWhenMetadataIsNotAnArray(mixed $input): void
    {
        self::assertNull($this->normalizer->normalize($input));
    }

    public function testReturnsNullWhenPathIsEmpty(): void
    {
        // Empty path means the handler did not actually produce a servable
        // asset (e.g. provider returned a description-only result). We
        // must not flip `file=1` for a row with no real file behind it,
        // otherwise the frontend would render a broken media player.
        self::assertNull($this->normalizer->normalize(['path' => '', 'type' => 'video']));
        self::assertNull($this->normalizer->normalize(['path' => '   ', 'type' => 'video']));
        self::assertNull($this->normalizer->normalize(['type' => 'video']));
    }

    public function testReturnsNullWhenPathIsNotAString(): void
    {
        self::assertNull($this->normalizer->normalize(['path' => 123, 'type' => 'video']));
        self::assertNull($this->normalizer->normalize(['path' => null, 'type' => 'video']));
    }

    public function testDefaultsTypeToEmptyStringWhenMissing(): void
    {
        // Edge case: a handler might forget to set the type. We still
        // persist the path so the file is reachable; the frontend has a
        // legacy extension-based fallback to infer the type.
        $result = $this->normalizer->normalize(['path' => '/api/v1/files/uploads/foo.mp4']);

        self::assertSame([
            'path' => '/api/v1/files/uploads/foo.mp4',
            'type' => '',
        ], $result);
    }

    public function testTrimsWhitespaceAroundPathAndType(): void
    {
        $result = $this->normalizer->normalize([
            'path' => '  /api/v1/files/uploads/foo.mp4  ',
            'type' => "video\n",
        ]);

        self::assertSame([
            'path' => '/api/v1/files/uploads/foo.mp4',
            'type' => 'video',
        ], $result);
    }
}
