<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileStorageService;
use App\Service\File\FileTypeResolver;
use App\Service\Message\MessagePreProcessor;
use PHPUnit\Framework\TestCase;

/**
 * #1300: one canonical "generic kind → concrete extension / category" resolver
 * shared by MessageClassifier and FileAnalysisHandler so generated files (which
 * store a generic BFILETYPE like 'audio') can never bypass file_analysis again.
 */
final class FileTypeResolverTest extends TestCase
{
    public function testConcreteExtensionInTypeIsTrusted(): void
    {
        self::assertSame('pdf', FileTypeResolver::resolveExtension('pdf', 'report.docx'));
    }

    public function testGenericKindRecoversExtensionFromFilename(): void
    {
        // Generated TTS audio: BFILETYPE='audio', filename carries the real ext.
        self::assertSame('mp3', FileTypeResolver::resolveExtension('audio', 'tts_123.mp3'));
        self::assertSame('mp4', FileTypeResolver::resolveExtension('video', 'clip.mp4'));
    }

    public function testGenericKindRecoversExtensionFromPathWhenNameHasNone(): void
    {
        self::assertSame('wav', FileTypeResolver::resolveExtension('audio', 'voice', '13/sess/voice.wav'));
    }

    public function testGenericKindWithoutAnyExtensionMapsToRepresentative(): void
    {
        self::assertSame('mp3', FileTypeResolver::resolveExtension('audio', 'voice'));
        self::assertSame('png', FileTypeResolver::resolveExtension('image', ''));
        self::assertSame('mp4', FileTypeResolver::resolveExtension('video', ''));
        self::assertSame('txt', FileTypeResolver::resolveExtension('document', ''));
    }

    public function testIsGenericFileKind(): void
    {
        self::assertTrue(FileTypeResolver::isGenericFileKind('audio'));
        self::assertTrue(FileTypeResolver::isGenericFileKind('IMAGE'));
        self::assertFalse(FileTypeResolver::isGenericFileKind('mp3'));
        self::assertFalse(FileTypeResolver::isGenericFileKind(''));
    }

    /**
     * The regression at the heart of #1300: a generated file stored with a
     * generic kind must resolve to the correct analyzable category so the
     * classifier routes it to file_analysis instead of the AI sorter + RAG.
     */
    public function testResolveCategoryForGeneratedGenericKinds(): void
    {
        self::assertSame('audio', FileTypeResolver::resolveCategory('audio', 'tts_1.mp3'));
        self::assertSame('audio', FileTypeResolver::resolveCategory('audio', 'voice')); // → mp3
        self::assertSame('video', FileTypeResolver::resolveCategory('video', 'render.mp4'));
        self::assertSame('document', FileTypeResolver::resolveCategory('document', 'out.pdf'));
        self::assertSame('image', FileTypeResolver::resolveCategory('image', 'gen.png'));
    }

    public function testResolveCategoryForConcreteExtensions(): void
    {
        self::assertSame('document', FileTypeResolver::resolveCategory('pdf', 'a.pdf'));
        self::assertSame('image', FileTypeResolver::resolveCategory('png', 'a.png'));
        self::assertSame('document', FileTypeResolver::resolveCategory('ics', 'invite.ics'));
        self::assertSame('document', FileTypeResolver::resolveCategory('odt', 'notes.odt'));
        self::assertSame('document', FileTypeResolver::resolveCategory('rtf', 'letter.rtf'));
        self::assertSame('document', FileTypeResolver::resolveCategory('pages', 'essay.pages'));
        self::assertSame('', FileTypeResolver::resolveCategory('xyz', 'a.xyz'));
    }

    /**
     * Issue #1907: every accepted upload that is not an image, audio, video,
     * HEIC (transcoded to JPEG on store), or a store-only archive must be a
     * document so the chat path extracts it and FileTypeResolver can route it.
     */
    public function testEveryAllowedNonMediaExtensionIsADocument(): void
    {
        $media = array_merge(
            MessagePreProcessor::IMAGE_EXTENSIONS,
            MessagePreProcessor::AUDIO_EXTENSIONS,
            MessagePreProcessor::VIDEO_EXTENSIONS,
            ['heic', 'heif'],
            FileStorageService::STORE_ONLY_EXTENSIONS,
        );

        foreach (FileStorageService::ALLOWED_EXTENSIONS as $ext) {
            if (in_array($ext, $media, true)) {
                continue;
            }

            self::assertContains(
                $ext,
                MessagePreProcessor::DOCUMENT_EXTENSIONS,
                $ext.' is allowed to upload but is never extracted on the chat path',
            );
            self::assertSame('document', FileTypeResolver::resolveCategory($ext, 'file.'.$ext));
        }
    }
}
