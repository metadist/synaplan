<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\OutboundChannelMedia;
use App\Service\Media\PublicMediaName;
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

    public function testPublishCopyWritesAnUnguessableFilename(): void
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
        $this->assertTrue(PublicMediaName::isUnguessable(basename($published)));
        $this->assertStringStartsWith('1_document_', basename($published));
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
