<?php

declare(strict_types=1);

namespace App\Plug\Extraction;

use App\Plug\PlugConfigService;
use App\Service\File\FileProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Walks extra extractors (then FileProcessor) so the admin Test button can
 * show every attempt: "Docling unavailable — Tika used instead".
 */
final readonly class ExtractionProbeService
{
    public function __construct(
        private ExtractionRegistry $registry,
        private PlugConfigService $plugConfig,
        private ExtractionQualityGate $qualityGate,
        private FileProcessor $fileProcessor,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/uploads')]
        private string $uploadDir,
    ) {
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
    public function testFile(string $absolutePath, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = mime_content_type($absolutePath) ?: '';
        $family = ExtractionRequest::familyFrom($mime, $ext);
        $request = new ExtractionRequest($absolutePath, $originalName, $mime, $ext, null, false, $family);

        $attempts = [];
        $winnerResult = null;
        $winnerKey = null;

        foreach ($this->plugConfig->extraExtractorKeys($family) as $key) {
            $adapter = $this->registry->byKey($key);
            $started = hrtime(true);
            if (null === $adapter) {
                $attempts[] = ['key' => $key, 'verdict' => 'unknown', 'ms' => 0];
                continue;
            }

            $verdict = 'skipped';
            try {
                if (!$adapter->supports($request)) {
                    $health = $adapter->health();
                    $verdict = $health->available ? 'unsupported' : 'unavailable';
                } else {
                    $result = $adapter->extract($request);
                    $gate = $this->qualityGate->verdict($result, $request);
                    $verdict = $gate->reason;
                    if ($gate->passed && ($result->hasText() || (null !== $result->markdown && '' !== trim($result->markdown)))) {
                        $winnerResult = $result;
                        $winnerKey = $key;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->info('ExtractionProbe: extra extractor failed', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
                $verdict = 'unavailable';
            }
            $attempts[] = [
                'key' => $key,
                'verdict' => $verdict,
                'ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ];
            if (null !== $winnerResult) {
                break;
            }
        }

        if (null === $winnerResult) {
            $relative = 'plugs-probe-'.bin2hex(random_bytes(8)).('' !== $ext ? '.'.$ext : '');
            $dest = rtrim($this->uploadDir, '/').'/'.$relative;
            if (!is_dir(\dirname($dest)) && !mkdir(\dirname($dest), 0775, true) && !is_dir(\dirname($dest))) {
                throw new \RuntimeException('Unable to create upload directory for extraction probe');
            }
            if (!copy($absolutePath, $dest)) {
                throw new \RuntimeException('Unable to stage file for extraction probe');
            }
            $started = hrtime(true);
            try {
                [$text, $meta] = $this->fileProcessor->extractText($relative, $ext);
                $strategy = \is_string($meta['strategy'] ?? null) ? $meta['strategy'] : 'unknown';
                $attempts[] = [
                    'key' => $strategy,
                    'verdict' => '' !== trim($text) ? 'quality_ok' : 'empty',
                    'ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                ];
                $markdown = \is_string($meta['markdown'] ?? null) ? $meta['markdown'] : null;
                $winnerKey = $strategy;
                $winnerResult = ExtractionResult::of($text, $strategy, $meta, $markdown);
            } finally {
                @unlink($dest);
            }
        }

        $previewSource = $winnerResult->markdown ?: $winnerResult->text;

        return [
            'winner' => $winnerKey,
            'strategy' => $winnerResult->strategy,
            'attempts' => $attempts,
            'preview' => mb_substr($previewSource, 0, 2000),
            'markdown' => null !== $winnerResult->markdown && '' !== $winnerResult->markdown,
        ];
    }
}
