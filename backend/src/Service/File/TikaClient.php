<?php

namespace App\Service\File;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Apache Tika Client for document text extraction.
 *
 * Extracts plain text from documents (PDF, DOCX, XLSX, PPTX, etc.)
 * using Apache Tika server.
 */
class TikaClient
{
    private bool $healthCheckDone = false;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $tikaUrl,
        private int $tikaTimeoutMs,
        private int $tikaRetries,
        private int $tikaRetryBackoffMs,
        private ?string $tikaHttpUser,
        private ?string $tikaHttpPass,
    ) {
    }

    /**
     * Extract text from a file using Apache Tika.
     *
     * @param string      $absoluteFilePath Path to the file
     * @param string|null $mimeType         MIME type of the file
     *
     * @return array [text, meta] where meta contains endpoint, attempts, elapsed_ms, http_code
     */
    public function extractText(string $absoluteFilePath, ?string $mimeType = null): array
    {
        $endpoint = rtrim($this->tikaUrl, '/').'/tika';

        // One-time health check
        $this->maybePingHealth();

        if (!is_file($absoluteFilePath) || 0 === filesize($absoluteFilePath)) {
            $this->logger->warning('Tika: Input file missing or empty', ['file' => $absoluteFilePath]);

            return [null, ['endpoint' => $this->tikaUrl, 'error' => 'File missing or empty']];
        }

        $size = filesize($absoluteFilePath);
        $this->logger->debug('Tika pre-call', [
            'endpoint' => $endpoint,
            'mime' => $mimeType ?? 'auto-detect',
            'size' => $size,
            'file' => basename($absoluteFilePath),
        ]);

        $attempt = 0;
        $startTs = microtime(true);
        $lastError = '';

        while ($attempt <= $this->tikaRetries) {
            ++$attempt;
            try {
                $options = [
                    'headers' => [
                        'Accept' => 'text/plain',
                        'User-Agent' => 'synaplan-tika-client',
                        'Expect' => '', // Avoid 100-continue delay
                    ],
                    'body' => fopen($absoluteFilePath, 'r'),
                    'timeout' => $this->tikaTimeoutMs / 1000,
                ];

                if ($mimeType) {
                    $options['headers']['Content-Type'] = $mimeType;
                }

                if ($this->tikaHttpUser) {
                    $options['auth_basic'] = [$this->tikaHttpUser, $this->tikaHttpPass ?? ''];
                }

                $response = $this->httpClient->request('PUT', $endpoint, $options);
                $statusCode = $response->getStatusCode();
                $content = $response->getContent();

                $elapsedMs = (int) ((microtime(true) - $startTs) * 1000);

                $this->logger->info('Tika extraction success', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'http_code' => $statusCode,
                    'elapsed_ms' => $elapsedMs,
                    'bytes' => strlen($content),
                ]);

                return [$content, [
                    'endpoint' => $this->tikaUrl,
                    'attempts' => $attempt,
                    'elapsed_ms' => $elapsedMs,
                    'http_code' => $statusCode,
                ]];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $elapsedMs = (int) ((microtime(true) - $startTs) * 1000);

                $this->logger->warning('Tika extraction attempt failed', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'elapsed_ms' => $elapsedMs,
                    'error' => $lastError,
                ]);

                if ($attempt <= $this->tikaRetries && $this->tikaRetryBackoffMs > 0) {
                    usleep($this->tikaRetryBackoffMs * 1000);
                }
            }
        }

        return [null, ['endpoint' => $this->tikaUrl, 'error' => $lastError]];
    }

    /**
     * Check if Tika service is available.
     */
    private function maybePingHealth(): void
    {
        if ($this->healthCheckDone) {
            return;
        }

        $this->healthCheckDone = true;
        $healthUrl = rtrim($this->tikaUrl, '/').'/version';

        try {
            $options = ['timeout' => 3];
            if ($this->tikaHttpUser) {
                $options['auth_basic'] = [$this->tikaHttpUser, $this->tikaHttpPass ?? ''];
            }

            $start = microtime(true);
            $response = $this->httpClient->request('GET', $healthUrl, $options);
            $statusCode = $response->getStatusCode();
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            $this->logger->debug('Tika health check', [
                'endpoint' => $healthUrl,
                'http_code' => $statusCode,
                'elapsed_ms' => $elapsed,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Tika health check failed', [
                'endpoint' => $healthUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if Tika is enabled (URL is configured).
     */
    public function isEnabled(): bool
    {
        return !empty($this->tikaUrl) && 'disabled' !== $this->tikaUrl;
    }
}
