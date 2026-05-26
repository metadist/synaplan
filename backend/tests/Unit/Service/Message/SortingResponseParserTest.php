<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\SortingResponseParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SortingResponseParserTest extends TestCase
{
    private SortingResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SortingResponseParser(new NullLogger());
    }

    public function testParsesLegacyFieldsWithoutBsteps(): void
    {
        $json = json_encode([
            'BTOPIC' => 'image-generation',
            'BLANG' => 'de',
            'BWEBSEARCH' => 1,
            'BMEDIA' => 'image',
        ], JSON_THROW_ON_ERROR);

        $parsed = $this->parser->parse($json, ['BTOPIC' => 'general', 'BLANG' => 'en']);

        self::assertSame('image-generation', $parsed['topic']);
        self::assertSame('de', $parsed['language']);
        self::assertTrue($parsed['web_search']);
        self::assertSame('image', $parsed['media_type']);
        self::assertNull($parsed['steps']);
    }

    public function testParsesBstepsArray(): void
    {
        $json = json_encode([
            'BTOPIC' => 'image-generation',
            'BLANG' => 'de',
            'BWEBSEARCH' => 1,
            'BMEDIA' => 'image',
            'BSTEPS' => [
                ['id' => 'answer', 'capability' => 'CHAT', 'web_search' => true],
                ['id' => 'generate', 'capability' => 'TEXT2PIC'],
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = $this->parser->parse($json, []);

        self::assertIsArray($parsed['steps']);
        self::assertCount(2, $parsed['steps']);
        self::assertSame('answer', $parsed['steps'][0]['id']);
        self::assertSame('CHAT', $parsed['steps'][0]['capability']);
        self::assertTrue($parsed['steps'][0]['web_search']);
        self::assertSame('generate', $parsed['steps'][1]['id']);
        self::assertSame('TEXT2PIC', $parsed['steps'][1]['capability']);
    }

    public function testSkipsInvalidBstepsEntries(): void
    {
        $json = json_encode([
            'BTOPIC' => 'general-chat',
            'BLANG' => 'en',
            'BSTEPS' => [
                ['id' => 'bad', 'capability' => 'UNKNOWN'],
                ['id' => 'ok', 'capability' => 'chat'],
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = $this->parser->parse($json, []);

        self::assertIsArray($parsed['steps']);
        self::assertCount(1, $parsed['steps']);
        self::assertSame('CHAT', $parsed['steps'][0]['capability']);
    }

    public function testTruncatesBstepsToMax(): void
    {
        $steps = [];
        for ($i = 0; $i < 8; ++$i) {
            $steps[] = ['id' => 'step'.$i, 'capability' => 'CHAT'];
        }

        $json = json_encode([
            'BTOPIC' => 'general-chat',
            'BLANG' => 'en',
            'BSTEPS' => $steps,
        ], JSON_THROW_ON_ERROR);

        $parsed = $this->parser->parse($json, []);

        self::assertIsArray($parsed['steps']);
        self::assertCount(SortingResponseParser::MAX_STEPS, $parsed['steps']);
    }
}
