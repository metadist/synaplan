<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\OcnoteText;
use PHPUnit\Framework\TestCase;

final class OcnoteTextTest extends TestCase
{
    public function testAPlainParagraphKeepsOnlyTheWords(): void
    {
        $json = '{"type":"doc","content":[{"type":"paragraph","attrs":{"textAlign":null},"content":[{"type":"text","text":"Der Hund heisst BERND"}]},{"type":"paragraph","attrs":{"textAlign":null}}]}';

        self::assertSame('Der Hund heisst BERND', OcnoteText::fromJson($json));
    }

    public function testTheEditorTreeBecomesReadableText(): void
    {
        $json = json_encode([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 2, 'textAlign' => 'center'],
                    'content' => [['type' => 'text', 'text' => 'Hunde']],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Siehe '],
                        [
                            'type' => 'text',
                            'text' => 'die Akte',
                            'marks' => [[
                                'type' => 'link',
                                'attrs' => ['href' => 'https://example.com/akte'],
                            ]],
                        ],
                        ['type' => 'text', 'text' => ' und'],
                        ['type' => 'hardBreak'],
                        [
                            'type' => 'text',
                            'text' => 'nicht hier',
                            'marks' => [[
                                'type' => 'link',
                                'attrs' => ['href' => 'javascript:alert(1)'],
                            ]],
                        ],
                    ],
                ],
                [
                    'type' => 'bulletList',
                    'content' => [[
                        'type' => 'listItem',
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => [['type' => 'text', 'text' => 'Bello']],
                        ]],
                    ]],
                ],
                [
                    'type' => 'orderedList',
                    'attrs' => ['start' => 3],
                    'content' => [[
                        'type' => 'listItem',
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => [['type' => 'text', 'text' => 'Dritter']],
                        ]],
                    ]],
                ],
                [
                    'type' => 'taskList',
                    'content' => [
                        [
                            'type' => 'taskItem',
                            'attrs' => ['checked' => true],
                            'content' => [[
                                'type' => 'paragraph',
                                'content' => [['type' => 'text', 'text' => 'Gefüttert']],
                            ]],
                        ],
                        [
                            'type' => 'taskItem',
                            'attrs' => ['checked' => false],
                            'content' => [[
                                'type' => 'paragraph',
                                'content' => [['type' => 'text', 'text' => 'Gassi']],
                            ]],
                        ],
                    ],
                ],
                [
                    'type' => 'blockquote',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'Wau']],
                    ]],
                ],
                [
                    'type' => 'codeBlock',
                    'attrs' => ['language' => 'php'],
                    'content' => [['type' => 'text', 'text' => 'echo 1;']],
                ],
                [
                    'type' => 'table',
                    'content' => [[
                        'type' => 'tableRow',
                        'content' => [
                            [
                                'type' => 'tableHeader',
                                'content' => [[
                                    'type' => 'paragraph',
                                    'content' => [['type' => 'text', 'text' => 'Name']],
                                ]],
                            ],
                            [
                                'type' => 'tableCell',
                                'content' => [[
                                    'type' => 'paragraph',
                                    'content' => [['type' => 'text', 'text' => 'Bernd']],
                                ]],
                            ],
                        ],
                    ]],
                ],
                ['type' => 'horizontalRule'],
                [
                    'type' => 'image',
                    'attrs' => [
                        'alt' => 'Portrait',
                        'src' => 'data:image/png;base64,AAAASECRET',
                    ],
                ],
                [
                    'type' => 'image',
                    'attrs' => ['src' => 'https://cdn.example/dog.png'],
                ],
                [
                    'type' => 'image',
                    'attrs' => ['src' => 'data:image/png;base64,BBBB'],
                ],
                [
                    'type' => 'callout',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'Hinweis']],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $text = OcnoteText::fromJson($json);

        self::assertNotNull($text);
        self::assertStringContainsString("## Hunde\n\nSiehe die Akte (https://example.com/akte) und\nnicht hier", $text);
        self::assertStringNotContainsString('javascript:', $text);
        self::assertStringContainsString('- Bello', $text);
        self::assertStringContainsString('3. Dritter', $text);
        self::assertStringContainsString('[x] Gefüttert', $text);
        self::assertStringContainsString('[ ] Gassi', $text);
        self::assertStringContainsString('> Wau', $text);
        self::assertStringContainsString("php\necho 1;", $text);
        self::assertStringContainsString('Name | Bernd', $text);
        self::assertStringContainsString('---', $text);
        self::assertStringContainsString('Portrait', $text);
        self::assertStringNotContainsString('AAAASECRET', $text);
        self::assertStringNotContainsString('data:', $text);
        self::assertStringContainsString('https://cdn.example/dog.png', $text);
        self::assertStringContainsString('[image]', $text);
        self::assertStringContainsString('Hinweis', $text);
    }

    public function testMixedFormattingInsideALinkKeepsTheWordTogether(): void
    {
        $json = json_encode([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'BER',
                        'marks' => [
                            ['type' => 'bold'],
                            ['type' => 'link', 'attrs' => ['href' => 'https://example.com/dog']],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'ND',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'https://example.com/dog']],
                        ],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        self::assertSame('BERND (https://example.com/dog)', OcnoteText::fromJson($json));
    }

    public function testGarbageIsNotANote(): void
    {
        self::assertNull(OcnoteText::fromJson('not json'));
        self::assertNull(OcnoteText::fromJson('{"type":"paragraph"}'));
        self::assertSame('', OcnoteText::fromJson('{"type":"doc"}'));
    }

    public function testFromFileReadsTheNote(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ocnote');
        self::assertIsString($path);
        file_put_contents($path, '{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Datei"}]}]}');

        try {
            self::assertSame('Datei', OcnoteText::fromFile($path));
            self::assertNull(OcnoteText::fromFile($path.'.missing'));
        } finally {
            unlink($path);
        }
    }
}
