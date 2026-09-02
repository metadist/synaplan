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

    public function testPrefersTheBracketSpanThatActuallyDecodes(): void
    {
        // The `[…]` span starts first but is prose, not the payload. Committing
        // to the earliest bracket would lose the object entirely.
        $result = $this->decoder->decode('See [appendix] for details. {"topic":"mediamaker"}');

        self::assertTrue($result->success);
        self::assertSame('mediamaker', $result->data['topic'] ?? null);
    }

    /**
     * `repair()`'s excess-brace pass only rewrites a `}}` that is followed by
     * `]` or `}`. A response ending in a bare `}}` therefore keeps the loop
     * condition true forever unless the loop bails out on no progress. A
     * regression here does not fail — it HANGS the request.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function unbalancedTrailingBrackets(): array
    {
        return [
            // Not recoverable — a trailing `}}` cannot be rewritten without
            // guessing where the excess brace belongs. Terminating and saying
            // "invalid_json" is the correct outcome.
            'one excess brace' => ['{"topic":"mediamaker"}}', false],
            'two excess braces' => ['{"topic":"mediamaker"}}}', false],
            'excess brace after array' => ['[{"id":1}]}}', false],
            'braces only' => ['}}', false],
            // Excess brackets DO have an unambiguous rewrite, so this one
            // recovers — proving the bail-out did not break the repair pass.
            'excess bracket' => ['[{"id":1}]]', true],
        ];
    }

    #[DataProvider('unbalancedTrailingBrackets')]
    public function testTerminatesOnUnbalancedTrailingBrackets(string $input, bool $recoverable): void
    {
        $result = $this->decoder->decode($input);

        self::assertSame($recoverable, $result->success);
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
