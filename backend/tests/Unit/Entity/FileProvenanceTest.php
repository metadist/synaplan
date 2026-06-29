<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the file provenance fields added in the Release 4.0
 * "provenance" joint sprint (03_file-management.md §3.1 subset): BSOURCE +
 * BORIGINALNAME. These let integrations (Nextcloud/OpenCloud/Outlook) label
 * where a file came from and preserve its original name.
 */
final class FileProvenanceTest extends TestCase
{
    public function testDefaultsToWebUpload(): void
    {
        $file = new File();

        self::assertSame('web_upload', $file->getSource());
        self::assertNull($file->getOriginalName());
    }

    #[DataProvider('validSources')]
    public function testAcceptsWhitelistedSource(string $source): void
    {
        $file = new File();
        $file->setSource($source);

        self::assertSame($source, $file->getSource());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function validSources(): iterable
    {
        foreach (File::SOURCES as $source) {
            yield $source => [$source];
        }
    }

    public function testUnknownSourceFallsBackToWebUpload(): void
    {
        $file = new File();
        $file->setSource('dropbox-typo');

        self::assertSame('web_upload', $file->getSource());
    }

    public function testOriginalNameIsTrimmedAndEmptyBecomesNull(): void
    {
        $file = new File();

        $file->setOriginalName('  /Shared/Q3 Report.pdf  ');
        self::assertSame('/Shared/Q3 Report.pdf', $file->getOriginalName());

        $file->setOriginalName('   ');
        self::assertNull($file->getOriginalName());

        $file->setOriginalName(null);
        self::assertNull($file->getOriginalName());
    }
}
