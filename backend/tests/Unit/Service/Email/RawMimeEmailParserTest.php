<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Service\Email\RawMimeEmailParser;
use PHPUnit\Framework\TestCase;

final class RawMimeEmailParserTest extends TestCase
{
    private RawMimeEmailParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RawMimeEmailParser();
    }

    public function testPreParsedPlainTextIsNotTreatedAsMime(): void
    {
        self::assertFalse($this->parser->looksLikeRawMime('Beschreibe das bild und gib es als audio wieder'));
    }

    public function testProseMentioningContentTypeIsNotMisclassified(): void
    {
        $body = "Hi, can you explain what the Content-Type header does in HTTP?\nThanks!";

        self::assertFalse($this->parser->looksLikeRawMime($body));
    }

    public function testDetectsRawMultipartMime(): void
    {
        self::assertTrue($this->parser->looksLikeRawMime($this->outlookStyleMime()));
    }

    /**
     * Regression for issue #1077: an Outlook email forwarded as raw MIME (text
     * part quoted-printable, JPEG attachment base64) must yield the clean text
     * the user actually wrote — never the boundaries, headers, or base64 blob.
     */
    public function testExtractsPlainTextFromMultipartWithAttachment(): void
    {
        $text = $this->parser->extractText($this->outlookStyleMime());

        self::assertSame('Beschreibe das bild und gib es als audio wieder', $text);
        self::assertStringNotContainsString('Content-Type', $text);
        self::assertStringNotContainsString('boundary', $text);
        self::assertStringNotContainsString('/9j/4AAQSkZJRg', $text);
    }

    public function testDecodesQuotedPrintableUmlauts(): void
    {
        $raw = implode("\r\n", [
            'Content-Type: text/plain; charset="utf-8"',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'Gr=C3=BC=C3=9Fe f=C3=BCr dich',
        ]);

        self::assertSame('Grüße für dich', $this->parser->extractText($raw));
    }

    public function testFallsBackToHtmlWhenNoPlainPart(): void
    {
        $raw = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset="utf-8"',
            'Content-Transfer-Encoding: 7bit',
            '',
            '<html><body><p>Hello <strong>world</strong></p></body></html>',
        ]);

        self::assertSame('Hello world', $this->parser->extractText($raw));
    }

    public function testReturnsEmptyStringWhenNothingExtractable(): void
    {
        $raw = implode("\r\n", [
            'Content-Type: application/octet-stream',
            'Content-Transfer-Encoding: base64',
            '',
            'AAAA',
        ]);

        self::assertSame('', $this->parser->extractText($raw));
    }

    private function outlookStyleMime(): string
    {
        return implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="000_BESPOKE_OUTER"',
            '',
            'This is a multipart message in MIME format.',
            '--000_BESPOKE_OUTER',
            'Content-Type: multipart/alternative; boundary="000_BESPOKE_INNER"',
            '',
            '--000_BESPOKE_INNER',
            'Content-Type: text/plain; charset="utf-8"',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'Beschreibe das bild und gib es als audio wieder',
            '',
            '--000_BESPOKE_INNER',
            'Content-Type: text/html; charset="utf-8"',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            '<html><body>Beschreibe das bild und gib es als audio wieder</body></html>',
            '--000_BESPOKE_INNER--',
            '',
            '--000_BESPOKE_OUTER',
            'Content-Type: image/jpeg; name="cat.jpg"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="cat.jpg"',
            '',
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkS',
            'Ew8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAAB',
            '',
            '--000_BESPOKE_OUTER--',
            '',
        ]);
    }
}
