<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\GeneratedMediaTextRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class GeneratedMediaTextRendererTest extends TestCase
{
    private GeneratedMediaTextRenderer $renderer;

    protected function setUp(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $dir = dirname(__DIR__, 4).'/translations';
        foreach (['de', 'en', 'es', 'fr', 'tr'] as $locale) {
            $translator->addResource('yaml', $dir.'/generated_media.'.$locale.'.yaml', $locale, 'generated_media');
        }
        $this->renderer = new GeneratedMediaTextRenderer($translator);
    }

    public function testForUserMatrix(): void
    {
        $cases = [
            GeneratedMediaTextRenderer::MARKER_IMAGE,
            GeneratedMediaTextRenderer::MARKER_VIDEO,
            GeneratedMediaTextRenderer::MARKER_AUDIO,
            GeneratedMediaTextRenderer::MARKER_FILE_PREFIX.'notes.pdf',
            GeneratedMediaTextRenderer::MARKER_FILE_FAILED,
        ];
        foreach ($cases as $marker) {
            foreach (['de', 'en', 'es', 'fr', 'tr'] as $locale) {
                $text = $this->renderer->forUser($marker, $locale);
                $this->assertNotSame('', trim($text), "{$locale}/{$marker}");
                $this->assertStringNotContainsString('__', $text, "{$locale}/{$marker}");
                $this->assertStringNotContainsString('Generated ', $text, "{$locale}/{$marker}");
            }
        }
    }

    public function testForModelNeverContainsMarkerOrGeneratedPrefix(): void
    {
        $cases = [
            GeneratedMediaTextRenderer::MARKER_IMAGE,
            GeneratedMediaTextRenderer::MARKER_VIDEO,
            GeneratedMediaTextRenderer::MARKER_AUDIO,
            GeneratedMediaTextRenderer::MARKER_FILE_PREFIX.'report.docx',
            GeneratedMediaTextRenderer::MARKER_FILE_FAILED,
            'Generated image: a blue lake at dawn',
            'Generated video: walking cat',
            'Generated audio: hello world',
        ];
        foreach ($cases as $marker) {
            $text = $this->renderer->forModel($marker);
            $this->assertNotSame('', trim($text), $marker);
            $this->assertStringNotContainsString('__', $text, $marker);
            $this->assertStringNotContainsString('Generated ', $text, $marker);
        }
    }

    public function testForUserKeepsFolderNoteSuffix(): void
    {
        $text = $this->renderer->forUser("__IMAGE_GENERATED__\n\nSaved to Projects/Art.", 'en');
        $this->assertStringContainsString('Saved to Projects/Art.', $text);
        $this->assertStringNotContainsString('__', $text);
    }

    public function testForUserReplacesAMarkerBuriedUnderProse(): void
    {
        $text = $this->renderer->forUser("Your report is ready.\n__FILE_GENERATED__:report.docx", 'en');

        $this->assertStringContainsString('Your report is ready.', $text);
        $this->assertStringContainsString('report.docx', $text);
        $this->assertStringNotContainsString('__', $text);
    }

    public function testIsMediaMarker(): void
    {
        $this->assertTrue($this->renderer->isMediaMarker('__IMAGE_GENERATED__'));
        $this->assertTrue($this->renderer->isMediaMarker('Generated image: foo'));
        $this->assertFalse($this->renderer->isMediaMarker('Hello there'));
    }

    public function testStorageMarker(): void
    {
        $this->assertSame('__IMAGE_GENERATED__', GeneratedMediaTextRenderer::storageMarker('image'));
        $this->assertSame('__VIDEO_GENERATED__', GeneratedMediaTextRenderer::storageMarker('video'));
        $this->assertSame('__AUDIO_GENERATED__', GeneratedMediaTextRenderer::storageMarker('audio'));
    }
}
