<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug\Extraction;

use App\Plug\Extraction\ContentExtractorInterface;
use App\Plug\Extraction\Docling\DoclingRejectedException;
use App\Plug\Extraction\Docling\DoclingUnavailableException;
use App\Plug\Extraction\ExtractionProbeService;
use App\Plug\Extraction\ExtractionQualityGate;
use App\Plug\Extraction\ExtractionRegistry;
use App\Plug\Extraction\ExtractionRequest;
use App\Plug\Extraction\ExtractionResult;
use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Service\File\FileProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ExtractionProbeServiceTest extends TestCase
{
    public function testHttp422IsRejectedNotUnavailable(): void
    {
        $result = $this->probeWithThrowingDocling(
            new DoclingRejectedException('Docling convert returned HTTP 422'),
        );

        $docling = $this->findAttempt($result, 'docling');
        self::assertSame('rejected', $docling['verdict']);
        self::assertSame('tika', $result['winner']);
        self::assertSame('tika', $result['strategy']);
    }

    public function testUnreachableSidecarStaysUnavailable(): void
    {
        $result = $this->probeWithThrowingDocling(
            new DoclingUnavailableException('Docling unavailable — connection refused'),
        );

        $docling = $this->findAttempt($result, 'docling');
        self::assertSame('unavailable', $docling['verdict']);
        self::assertSame('tika', $result['winner']);
    }

    /**
     * @return array{
     *     winner: string|null,
     *     strategy: string,
     *     attempts: list<array{key: string, verdict: string, ms: int}>,
     *     preview: string,
     *     markdown: bool
     * }
     */
    private function probeWithThrowingDocling(\Throwable $error): array
    {
        $extra = new class($error) implements ContentExtractorInterface {
            public function __construct(private \Throwable $error)
            {
            }

            public function key(): string
            {
                return 'docling';
            }

            public function descriptor(): PlugDescriptor
            {
                return new PlugDescriptor('docling', 'Docling', '', [], 'self-hosted');
            }

            public function supports(ExtractionRequest $request): bool
            {
                return '' !== $request->absolutePath;
            }

            public function extract(ExtractionRequest $request): ExtractionResult
            {
                throw $this->error;
            }

            public function health(): PlugHealth
            {
                return PlugHealth::available();
            }
        };

        $plugConfig = $this->createMock(PlugConfigService::class);
        $plugConfig->method('extraExtractorKeys')->willReturn(['docling']);

        $fileProcessor = $this->createMock(FileProcessor::class);
        $fileProcessor->method('extractText')->willReturn([
            'Tika fallback body',
            ['strategy' => 'tika'],
        ]);

        $uploadDir = sys_get_temp_dir().'/synaplan-probe-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($uploadDir, 0775, true) || is_dir($uploadDir));

        $probe = new ExtractionProbeService(
            new ExtractionRegistry([$extra], $plugConfig, new NullLogger()),
            $plugConfig,
            $this->createStub(ExtractionQualityGate::class),
            $fileProcessor,
            new NullLogger(),
            $uploadDir,
        );

        $path = tempnam(sys_get_temp_dir(), 'probe-pdf-');
        self::assertNotFalse($path);
        file_put_contents($path, "%PDF-1.4 probe\n");

        try {
            return $probe->testFile($path, 'invoice.pdf');
        } finally {
            @unlink($path);
            foreach (glob($uploadDir.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($uploadDir);
        }
    }

    /**
     * @param array{attempts: list<array{key: string, verdict: string, ms: int}>} $result
     *
     * @return array{key: string, verdict: string, ms: int}
     */
    private function findAttempt(array $result, string $key): array
    {
        foreach ($result['attempts'] as $attempt) {
            if ($key === $attempt['key']) {
                return $attempt;
            }
        }

        self::fail('Missing attempt for '.$key);
    }
}
