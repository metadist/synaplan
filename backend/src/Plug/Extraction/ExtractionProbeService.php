<?php

declare(strict_types=1);

namespace App\Plug\Extraction;

use App\Service\File\FileProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stages a sample and runs the same FileProcessor chain a real upload uses,
 * so the admin Test button lists every adapter attempt and the same winner.
 */
final readonly class ExtractionProbeService
{
    public function __construct(
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
    public function testFile(string $absolutePath, string $originalName, ?int $userId = null): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $relative = 'plugs-probe-'.bin2hex(random_bytes(8)).('' !== $ext ? '.'.$ext : '');
        $dest = rtrim($this->uploadDir, '/').'/'.$relative;
        if (!is_dir(\dirname($dest)) && !mkdir(\dirname($dest), 0775, true) && !is_dir(\dirname($dest))) {
            throw new \RuntimeException('Unable to create upload directory for extraction probe');
        }
        if (!copy($absolutePath, $dest)) {
            throw new \RuntimeException('Unable to stage file for extraction probe');
        }

        try {
            [$text, $meta] = $this->fileProcessor->extractText($relative, $ext, $userId);
            $strategy = \is_string($meta['strategy'] ?? null) ? $meta['strategy'] : 'unknown';
            $markdown = \is_string($meta['markdown'] ?? null) ? $meta['markdown'] : null;
            $attempts = $this->normalizeAttempts($meta['attempts'] ?? null, $strategy, '' !== trim($text));
            $previewSource = (null !== $markdown && '' !== $markdown) ? $markdown : $text;

            return [
                'winner' => '' !== $strategy && 'chain_exhausted' !== $strategy ? $strategy : null,
                'strategy' => $strategy,
                'attempts' => $attempts,
                'preview' => mb_substr($previewSource, 0, 2000),
                'markdown' => null !== $markdown && '' !== $markdown,
            ];
        } catch (\Throwable $e) {
            $this->logger->info('ExtractionProbe: FileProcessor failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            @unlink($dest);
        }
    }

    /**
     * @return list<array{key: string, verdict: string, ms: int}>
     */
    private function normalizeAttempts(mixed $raw, string $strategy, bool $hasText): array
    {
        if (!\is_array($raw)) {
            return [[
                'key' => $strategy,
                'verdict' => $hasText ? 'quality_ok' : 'empty',
                'ms' => 0,
            ]];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!\is_array($row) || !\is_string($row['key'] ?? null) || !\is_string($row['verdict'] ?? null)) {
                continue;
            }
            $out[] = [
                'key' => $row['key'],
                'verdict' => $row['verdict'],
                'ms' => \is_int($row['ms'] ?? null) ? $row['ms'] : (int) ($row['ms'] ?? 0),
            ];
        }

        return [] !== $out ? $out : [[
            'key' => $strategy,
            'verdict' => $hasText ? 'quality_ok' : 'empty',
            'ms' => 0,
        ]];
    }
}
