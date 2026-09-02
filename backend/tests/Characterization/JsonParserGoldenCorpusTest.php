<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Controller\UserMemoryController;
use App\Service\FeedbackContradictionService;
use App\Service\File\FileGenerationEnvelope;
use App\Service\Message\MediaPromptExtractor;
use App\Service\Message\MessageSorter;
use App\Service\Multitask\TaskPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden-corpus documentation of the JSON-recovery tolerance of the SIX
 * independently grown parsers this repo uses to turn a raw model reply into
 * structured data:
 *
 *  1. {@see MessageSorter::parseResponse()}            — tools:sort
 *  2. {@see TaskPlanner::decodeJson()}                  — tools:plan
 *  3. {@see MediaPromptExtractor::decodeJson()}         — media prompt refinement
 *  4. {@see FeedbackContradictionService::extractJson()} — tools:feedback_contradiction_check
 *  5. {@see UserMemoryController::parseAiResponse()}    — memory action extraction
 *  6. {@see FileGenerationEnvelope::extract()}           — officemaker envelope
 *
 * Each parser independently re-invented fence-stripping, prose-extraction and
 * truncation-repair — with different results. This test feeds all six the
 * SAME five shapes a live model can emit (clean, fenced, prose-embedded,
 * truncated, smart-quoted) and pins the observed outcome. It is
 * intentionally NOT a correctness test — a "FAIL" row is not a bug, it is
 * today's documented behavior.
 *
 * Purpose (structured-output refactor, phase 0b): this is the acceptance
 * bar for {@see \App\AI\StructuredOutput\JsonResponseDecoder} once it lands —
 * the consolidated decoder must recover at least everything a row below
 * marks RECOVERED, or the change is a regression in fault tolerance that
 * would otherwise only be discovered in production. Diff this file's
 * expectations deliberately; never adjust them to make a red run green
 * without understanding why the recovery rate moved.
 */
final class JsonParserGoldenCorpusTest extends TestCase
{
    /**
     * The five shapes every corpus payload is rendered as, matching the
     * malformed outputs live models are known to produce.
     *
     * @return array<string, callable(string): string>
     */
    private static function shapes(): array
    {
        return [
            'clean' => static fn (string $json): string => $json,
            'fenced' => static fn (string $json): string => "```json\n{$json}\n```",
            'prose_embedded' => static fn (string $json): string => "Here is the result, hope it helps: {$json} Let me know if you need anything else!",
            // Simulates a response cut off mid-object because the completion
            // budget ran out — a real failure mode for reasoning models that
            // spend tokens thinking before emitting JSON (see
            // MessageSorter::CLASSIFICATION_MAX_TOKENS).
            'truncated' => static fn (string $json): string => substr($json, 0, max(1, strlen($json) - 15)),
            // Some clients/models substitute typographic quotes for straight
            // ASCII quotes (autocorrect, copy-paste from a rendered chat UI).
            'smart_quotes' => static function (string $json): string {
                $out = '';
                $toggle = true;
                for ($i = 0; $i < strlen($json); ++$i) {
                    $char = $json[$i];
                    if ('"' === $char) {
                        $out .= $toggle ? "\u{201C}" : "\u{201D}";
                        $toggle = !$toggle;
                    } else {
                        $out .= $char;
                    }
                }

                return $out;
            },
        ];
    }

    private static function newBare(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function setProp(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }

    private static function invoke(object $object, string $method, array $args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invokeArgs($object, $args);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function sorterShapes(): array
    {
        return [
            'clean' => ['clean', true],
            'fenced' => ['fenced', true],
            'prose_embedded' => ['prose_embedded', false],
            'truncated' => ['truncated', false],
            'smart_quotes' => ['smart_quotes', false],
        ];
    }

    #[DataProvider('sorterShapes')]
    public function testMessageSorterParseResponse(string $shapeName, bool $expectRecovered): void
    {
        // Distinguishing values (NOT the fallback defaults 'general'/'en') so
        // a silent fallback can be told apart from a real recovery.
        $json = '{"BTOPIC":"mediamaker","BLANG":"de","BWEBSEARCH":0,"BMULTI":0}';
        $sorter = self::newBare(MessageSorter::class);
        self::setProp($sorter, 'logger', new NullLogger());

        $input = self::shapes()[$shapeName]($json);
        $result = self::invoke($sorter, 'parseResponse', [$input, []]);

        $recovered = 'mediamaker' === ($result['topic'] ?? null) && 'de' === ($result['language'] ?? null);

        self::assertSame($expectRecovered, $recovered, "MessageSorter::parseResponse() on shape '{$shapeName}' — recovery expectation changed, see class docblock");
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function plannerShapes(): array
    {
        return [
            'clean' => ['clean', true],
            'fenced' => ['fenced', true],
            'prose_embedded' => ['prose_embedded', true],
            'truncated' => ['truncated', false],
            'smart_quotes' => ['smart_quotes', false],
        ];
    }

    #[DataProvider('plannerShapes')]
    public function testTaskPlannerDecodeJson(string $shapeName, bool $expectRecovered): void
    {
        $json = '{"steps":[{"id":"a","capability":"chat"}]}';
        $planner = self::newBare(TaskPlanner::class);

        $input = self::shapes()[$shapeName]($json);
        $result = self::invoke($planner, 'decodeJson', [$input]);

        $recovered = is_array($result) && isset($result['steps']);

        self::assertSame($expectRecovered, $recovered, "TaskPlanner::decodeJson() on shape '{$shapeName}' — recovery expectation changed, see class docblock");
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function mediaPromptShapes(): array
    {
        return [
            'clean' => ['clean', true],
            'fenced' => ['fenced', true],
            'prose_embedded' => ['prose_embedded', false],
            'truncated' => ['truncated', false],
            'smart_quotes' => ['smart_quotes', false],
        ];
    }

    #[DataProvider('mediaPromptShapes')]
    public function testMediaPromptExtractorDecodeJson(string $shapeName, bool $expectRecovered): void
    {
        $json = '{"prompt":"a red bicycle","mediaType":"image"}';
        $extractor = self::newBare(MediaPromptExtractor::class);

        $input = self::shapes()[$shapeName]($json);
        $normalized = self::invoke($extractor, 'normalizeContent', [$input]);
        $result = self::invoke($extractor, 'decodeJson', [$normalized]);

        $recovered = is_array($result) && isset($result['prompt']);

        self::assertSame($expectRecovered, $recovered, "MediaPromptExtractor::decodeJson() on shape '{$shapeName}' — recovery expectation changed, see class docblock");
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function feedbackShapes(): array
    {
        return [
            'clean' => ['clean', true],
            'fenced' => ['fenced', true],
            'prose_embedded' => ['prose_embedded', true],
            'truncated' => ['truncated', false],
            'smart_quotes' => ['smart_quotes', false],
        ];
    }

    #[DataProvider('feedbackShapes')]
    public function testFeedbackContradictionServiceExtractJson(string $shapeName, bool $expectRecovered): void
    {
        $json = '{"contradictions":[{"id":1,"type":"memory","value":"x","reason":"y"}]}';
        $service = self::newBare(FeedbackContradictionService::class);

        $input = self::shapes()[$shapeName]($json);
        $result = self::invoke($service, 'extractJson', [$input]);

        $recovered = is_array($result) && isset($result['contradictions']);

        self::assertSame($expectRecovered, $recovered, "FeedbackContradictionService::extractJson() on shape '{$shapeName}' — recovery expectation changed, see class docblock");
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function memoryControllerShapes(): array
    {
        return [
            'clean' => ['clean', true],
            'fenced' => ['fenced', true],
            'prose_embedded' => ['prose_embedded', false],
            'truncated' => ['truncated', false],
            'smart_quotes' => ['smart_quotes', false],
        ];
    }

    #[DataProvider('memoryControllerShapes')]
    public function testUserMemoryControllerParseAiResponseActionsFormat(string $shapeName, bool $expectRecovered): void
    {
        $json = '{"actions":[{"action":"create","memory":{"category":"preferences","key":"color","value":"blue"}}]}';
        $controller = self::newBare(UserMemoryController::class);

        $input = self::shapes()[$shapeName]($json);
        $result = self::invoke($controller, 'parseAiResponse', [$input, 'test input', []]);

        $recovered = is_array($result) && [] !== $result && 'create' === ($result[0]['action'] ?? null);

        self::assertSame($expectRecovered, $recovered, "UserMemoryController::parseAiResponse() [actions format] on shape '{$shapeName}' — recovery expectation changed, see class docblock");
    }

    /**
     * Legacy single-action format {"action": ..., "memory": {...}} — also
     * the shape the NDJSON fallback (Format 3 in the method docblock)
     * scans for line by line. Same shapes, same outcome: the NDJSON path
     * only helps when the model actually emits newline-separated objects,
     * not when prose and JSON share one line.
     */
    #[DataProvider('memoryControllerShapes')]
    public function testUserMemoryControllerParseAiResponseSingleActionFormat(string $shapeName, bool $expectRecovered): void
    {
        $json = '{"action":"create","memory":{"category":"preferences","key":"color","value":"blue"}}';
        $controller = self::newBare(UserMemoryController::class);

        $input = self::shapes()[$shapeName]($json);
        $result = self::invoke($controller, 'parseAiResponse', [$input, 'test input', []]);

        $recovered = is_array($result) && [] !== $result && 'create' === ($result[0]['action'] ?? null);

        self::assertSame($expectRecovered, $recovered, "UserMemoryController::parseAiResponse() [single-action format] on shape '{$shapeName}' — recovery expectation changed, see class docblock");
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function fileEnvelopeShapes(): array
    {
        return [
            'clean' => ['clean', true],
            'fenced' => ['fenced', true],
            'prose_embedded' => ['prose_embedded', true],
            'truncated' => ['truncated', false],
            'smart_quotes' => ['smart_quotes', false],
        ];
    }

    #[DataProvider('fileEnvelopeShapes')]
    public function testFileGenerationEnvelopeExtract(string $shapeName, bool $expectRecovered): void
    {
        $json = '{"BFILEPATH":"report.docx","BFILETEXT":"Hello world content"}';

        $input = self::shapes()[$shapeName]($json);
        $result = FileGenerationEnvelope::extract($input);

        $recovered = null !== $result && 'report.docx' === $result['filename'];

        self::assertSame($expectRecovered, $recovered, "FileGenerationEnvelope::extract() on shape '{$shapeName}' — recovery expectation changed, see class docblock");
    }
}
