<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the file-world data-model fields added in Release 4.0
 * Feature 2 (03_file-management.md §3.1): origin kind, incoming flag, staging
 * path, message link, vector state, chunk count, provider, thumbnail.
 */
final class FileWorldFieldsTest extends TestCase
{
    public function testDefaults(): void
    {
        $file = new File();

        self::assertNull($file->getOriginKind());
        self::assertFalse($file->isIncoming());
        self::assertNull($file->getStagePath());
        self::assertNull($file->getMessageId());
        self::assertSame(File::VECTOR_STATE_NONE, $file->getVectorState());
        self::assertSame(0, $file->getChunkCount());
        self::assertNull($file->getProvider());
        self::assertNull($file->getThumbPath());
    }

    #[DataProvider('validOriginKinds')]
    public function testAcceptsWhitelistedOriginKind(string $kind): void
    {
        $file = new File();
        $file->setOriginKind($kind);

        self::assertSame($kind, $file->getOriginKind());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function validOriginKinds(): iterable
    {
        foreach (File::ORIGIN_KINDS as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testUnknownOriginKindBecomesNull(): void
    {
        $file = new File();
        $file->setOriginKind('hologram');

        self::assertNull($file->getOriginKind());
    }

    #[DataProvider('validVectorStates')]
    public function testAcceptsWhitelistedVectorState(string $state): void
    {
        $file = new File();
        $file->setVectorState($state);

        self::assertSame($state, $file->getVectorState());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function validVectorStates(): iterable
    {
        foreach (File::VECTOR_STATES as $state) {
            yield $state => [$state];
        }
    }

    public function testUnknownVectorStateFallsBackToNone(): void
    {
        $file = new File();
        $file->setVectorState('quantum');

        self::assertSame(File::VECTOR_STATE_NONE, $file->getVectorState());
    }

    public function testChunkCountNeverNegative(): void
    {
        $file = new File();
        $file->setChunkCount(-5);

        self::assertSame(0, $file->getChunkCount());
    }

    public function testDisplayNamePrefersOriginalName(): void
    {
        $file = new File();
        $file->setFileName('Q3-Report_173.pdf');
        self::assertSame('Q3-Report_173.pdf', $file->getDisplayName());

        $file->setOriginalName('Q3 Report.pdf');
        self::assertSame('Q3 Report.pdf', $file->getDisplayName());
    }

    public function testIncomingSourcesAreASubsetOfSources(): void
    {
        foreach (File::INCOMING_SOURCES as $source) {
            self::assertContains($source, File::SOURCES);
        }
    }

    #[DataProvider('incomingCases')]
    public function testArrivesIncoming(string $source, ?string $groupKey, bool $expected): void
    {
        self::assertSame($expected, File::arrivesIncoming($source, $groupKey));
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: bool}>
     */
    public static function incomingCases(): iterable
    {
        foreach (['nextcloud', 'opencloud'] as $source) {
            yield "$source into a knowledge folder" => [$source, 'Contracts', false];
            yield "$source into DEFAULT" => [$source, 'DEFAULT', true];
            yield "$source without a folder" => [$source, null, true];
            yield "$source with an empty folder" => [$source, '', true];
        }
        yield 'outlook into a knowledge folder' => ['outlook', 'Contracts', true];
        yield 'web upload into a knowledge folder' => ['web_upload', 'Contracts', false];
        yield 'web upload without a folder' => ['web_upload', null, false];
    }

    public function testNamesKnowledgeFolderOnlyForCloudAppsWithARealFolder(): void
    {
        self::assertTrue(File::namesKnowledgeFolder('owncloud', 'Contracts'));
        self::assertFalse(File::namesKnowledgeFolder('nextcloud', 'DEFAULT'));
        self::assertFalse(File::namesKnowledgeFolder('opencloud', null));
        self::assertFalse(File::namesKnowledgeFolder('outlook', 'Contracts'));
        self::assertFalse(File::namesKnowledgeFolder('web_upload', 'Contracts'));
    }

    public function testIsMediaForImageType(): void
    {
        $file = new File();
        $file->setFileType('png');

        self::assertTrue($file->isMedia());
    }

    public function testIsMediaForGeneratedVideoKind(): void
    {
        $file = new File();
        $file->setFileType('mp4');
        $file->setSource('generated');
        $file->setOriginKind('video');

        self::assertTrue($file->isMedia());
    }

    public function testGeneratedDocumentIsNotMedia(): void
    {
        $file = new File();
        $file->setFileType('docx');
        $file->setSource('generated');
        $file->setOriginKind('document');

        self::assertFalse($file->isMedia());
    }

    public function testIncomingFlagRoundTrip(): void
    {
        $file = new File();
        $file->setIncoming(true);
        self::assertTrue($file->isIncoming());

        $file->setStagePath('  incoming/nextcloud/u1/brief.docx  ');
        self::assertSame('incoming/nextcloud/u1/brief.docx', $file->getStagePath());

        $file->setIncoming(false);
        $file->setStagePath(null);
        self::assertFalse($file->isIncoming());
        self::assertNull($file->getStagePath());
    }

    public function testMarkVectorizedMakesTheFileSearchableAtOnce(): void
    {
        $file = new File();
        self::assertSame(File::VECTOR_STATE_NONE, $file->getVectorState());

        $file->markVectorized();

        self::assertSame('vectorized', $file->getStatus());
        self::assertSame(File::VECTOR_STATE_VECTORIZED, $file->getVectorState());
    }
}
