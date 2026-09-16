<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\AI\Service\AiFacade;
use App\Plug\Extraction\Adapter\NativeTextExtractor;
use App\Plug\Extraction\ExtractionRequest;
use App\Service\File\FileProcessor;
use App\Service\File\HeicConverter;
use App\Service\File\PdfRasterizer;
use App\Service\File\TextCleaner;
use App\Service\File\TikaClient;
use App\Service\File\VideoAnalysisService;
use App\Service\WhisperService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * C1 for S1: native-text files stay identical through the adapter and
 * FileProcessor. Tika/vision/STT recorded responses land in S2.
 */
final class ExtractionGoldenCorpusTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function nativeFiles(): array
    {
        return [
            'markdown' => ['sample.md', 'text/markdown', 'md'],
            'csv' => ['sample.csv', 'text/csv', 'csv'],
            'html' => ['sample.html', 'text/html', 'html'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeFiles')]
    public function testNativeExtractorMatchesExpectedTextAndStrategy(string $file, string $mime, string $ext): void
    {
        $absolute = $this->filesDir().'/'.$file;
        $this->assertFileExists($absolute);

        $result = (new NativeTextExtractor(new TextCleaner()))->extract(new ExtractionRequest(
            $absolute,
            'files/'.$file,
            $mime,
            $ext,
            null,
            false,
            'text',
        ));

        $this->assertSame('native_text', $result->strategy);
        $this->assertSame($this->expectedText($file), $result->text);
        $this->assertSame($this->expectedText($file), $result->toLegacyPair()[0]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeFiles')]
    public function testFileProcessorNativePathMatchesExpectedText(string $file, string $mime, string $ext): void
    {
        $this->assertNotSame('', $mime);
        $processor = new FileProcessor(
            $this->createStub(TikaClient::class),
            $this->createStub(PdfRasterizer::class),
            new TextCleaner(),
            $this->createStub(AiFacade::class),
            $this->createStub(WhisperService::class),
            $this->createStub(VideoAnalysisService::class),
            new HeicConverter(new NullLogger()),
            new NullLogger(),
            $this->corpusDir(),
            10,
            3.0,
            '/nonexistent/ffmpeg',
        );

        [$text, $meta] = $processor->extractText('files/'.$file, $ext);

        $this->assertSame('native_text', $meta['strategy'] ?? null);
        $this->assertSame($this->expectedText($file), $text);
    }

    public function testManifestSha256MatchesOnDiskFiles(): void
    {
        $manifestPath = $this->corpusDir().'/manifest.json';
        $this->assertFileExists($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertNotEmpty($manifest['files'] ?? null);

        foreach ($manifest['files'] as $entry) {
            $this->assertIsArray($entry);
            $path = $this->corpusDir().'/'.$entry['file'];
            $this->assertFileExists($path);
            $this->assertSame($entry['sha256'], hash_file('sha256', $path));
            $this->assertSame('native_text', $entry['strategy']);
        }
    }

    private function expectedText(string $file): string
    {
        $expected = (string) file_get_contents($this->corpusDir().'/expected/'.$file.'.txt');

        return (new TextCleaner())->clean($expected);
    }

    private function corpusDir(): string
    {
        return dirname(__DIR__, 2).'/Fixtures/extraction';
    }

    private function filesDir(): string
    {
        return $this->corpusDir().'/files';
    }
}
