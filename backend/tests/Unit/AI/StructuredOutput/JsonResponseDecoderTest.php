<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput;

use App\AI\StructuredOutput\JsonResponseDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the consolidated decoder meets the phase-0b acceptance bar: it
 * must recover at least everything
 * {@see \App\Tests\Characterization\JsonParserGoldenCorpusTest} marks
 * RECOVERED for any of the six legacy parsers it documents, for both object
 * and array payloads.
 */
final class JsonResponseDecoderTest extends TestCase
{
    private JsonResponseDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new JsonResponseDecoder();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function recoverableShapes(): array
    {
        $json = '{"topic":"mediamaker","lang":"de"}';

        return [
            'clean' => [$json],
            'fenced' => ["```json\n{$json}\n```"],
            'fenced_no_language_tag' => ["```\n{$json}\n```"],
            'prose_embedded' => ["Here is the result, hope it helps: {$json} Let me know if you need anything else!"],
        ];
    }

    #[DataProvider('recoverableShapes')]
    public function testRecoversEveryGoldenCorpusShape(string $input): void
    {
        $result = $this->decoder->decode($input);

        self::assertTrue($result->success, 'expected successful decode for: '.$input);
        self::assertSame('mediamaker', $result->data['topic'] ?? null);
        self::assertSame('de', $result->data['lang'] ?? null);
    }

    public function testRecoversTruncatedResponseMissingClosingBraces(): void
    {
        // A response cut off by a token budget mid-structure — the exact
        // failure mode MessageSorter::CLASSIFICATION_MAX_TOKENS documents.
        $result = $this->decoder->decode('{"topic":"mediamaker","lang":"de"');

        self::assertTrue($result->success);
        self::assertSame('mediamaker', $result->data['topic'] ?? null);
    }

    public function testRecoversTopLevelArrayEmbeddedInProse(): void
    {
        $result = $this->decoder->decode('Here you go: [{"id":1},{"id":2}] hope that helps');

        self::assertTrue($result->success);
        self::assertSame([['id' => 1], ['id' => 2]], $result->data);
    }

    public function testFailsOnEmptyResponse(): void
    {
        $result = $this->decoder->decode('');

        self::assertFalse($result->success);
        self::assertSame('empty_response', $result->errorReason);
        self::assertNull($result->data);
    }

    public function testFailsOnWhitespaceOnlyResponse(): void
    {
        $result = $this->decoder->decode("   \n\t  ");

        self::assertFalse($result->success);
    }

    public function testFailsOnNonJsonProseWithNoObjectOrArray(): void
    {
        $result = $this->decoder->decode('Sorry, I cannot help with that request.');

        self::assertFalse($result->success);
        self::assertSame('invalid_json', $result->errorReason);
    }

    public function testFailsOnSmartQuotes(): void
    {
        // Matches the golden corpus: no legacy parser recovers this shape
        // either, so the consolidated decoder is not expected to invent new
        // tolerance here.
        $result = $this->decoder->decode("{\u{201C}topic\u{201D}: \u{201C}mediamaker\u{201D}}");

        self::assertFalse($result->success);
    }
}
