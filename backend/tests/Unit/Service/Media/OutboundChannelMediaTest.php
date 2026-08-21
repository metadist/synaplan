<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\OutboundChannelMedia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboundChannelMediaTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    #[DataProvider('anonymouslyFetchableProvider')]
    public function testIsAnonymouslyFetchable(string $filename, bool $expected): void
    {
        $this->assertSame($expected, OutboundChannelMedia::isAnonymouslyFetchable($filename));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function anonymouslyFetchableProvider(): iterable
    {
        yield 'tts prefix' => ['tts_reply.mp3', true];
        yield 'ai image pattern' => ['123_google_1736189000.mp4', true];
        yield 'published document copy' => ['1_document_1736189000847.docx', true];
        yield 'i2v source' => ['ai_i2vsrc_1_1736189000_abc.jpg', true];
        yield 'generated office doc' => ['notes_1736189000.docx', false];
        yield 'human filename' => ['meeting.ics', false];
        yield 'legacy generated prefix' => ['generated_abc123.png', false];
    }

    public function testRelativeUploadPathStripsServePrefix(): void
    {
        $this->assertSame(
            '01/000/00001/2026/08/notes_1736189000.docx',
            OutboundChannelMedia::relativeUploadPath('/api/v1/files/uploads/01/000/00001/2026/08/notes_1736189000.docx'),
        );
        $this->assertSame(
            '01/000/00001/2026/08/notes_1736189000.docx',
            OutboundChannelMedia::relativeUploadPath('01/000/00001/2026/08/notes_1736189000.docx'),
        );
    }

    public function testPublishCopyWritesAnonymouslyFetchableFilename(): void
    {
        $uploadDir = sys_get_temp_dir().'/outbound-media-'.bin2hex(random_bytes(4));
        $source = $uploadDir.'/source/notes_1736189000.docx';
        $this->assertTrue(@mkdir(dirname($source), 0775, true));
        file_put_contents($source, 'docx-bytes');
        $this->tempFiles[] = $source;

        $published = OutboundChannelMedia::publishCopy(
            $source,
            $uploadDir,
            1,
            'document',
            new UserUploadPathBuilder(),
        );

        $this->assertIsString($published);
        $this->assertTrue(OutboundChannelMedia::isAnonymouslyFetchable(basename($published)));
        $this->assertStringEndsWith('.docx', $published);
        $this->assertFileExists($uploadDir.'/'.$published);
        $this->assertSame('docx-bytes', (string) file_get_contents($uploadDir.'/'.$published));
        $this->tempFiles[] = $uploadDir.'/'.$published;
    }

    public function testPublishCopyReturnsNullWhenSourceMissing(): void
    {
        $this->assertNull(OutboundChannelMedia::publishCopy(
            '/tmp/does-not-exist-'.bin2hex(random_bytes(4)).'.docx',
            sys_get_temp_dir(),
            1,
            'document',
            new UserUploadPathBuilder(),
        ));
    }
}
