<?php

declare(strict_types=1);

namespace App\Plug\Extraction\Docling;

use App\Plug\PlugHealth;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin HTTP client for docling-serve. No Python in the PHP image.
 *
 * GET  {base}/health
 * POST {base}/v1/convert/file  multipart files + to_formats=md,text
 */
final class DoclingClient
{
    private const HEALTH_CACHE_SECONDS = 30;

    private ?PlugHealth $cachedHealth = null;
    private int $cachedAt = 0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $baseUrl,
        private int $timeoutMs,
    ) {
    }

    public function isEnabled(): bool
    {
        $url = trim($this->baseUrl);

        return '' !== $url && 'disabled' !== strtolower($url);
    }

    public function health(): PlugHealth
    {
        if (null !== $this->cachedHealth && (time() - $this->cachedAt) < self::HEALTH_CACHE_SECONDS) {
            return $this->cachedHealth;
        }

        if (!$this->isEnabled()) {
            return $this->remember(PlugHealth::unavailable('DOCLING_BASE_URL is unset or disabled'));
        }

        try {
            $response = $this->httpClient->request('GET', $this->endpoint('/health'), [
                'timeout' => 5,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 500) {
                return $this->remember(PlugHealth::unavailable('Docling health returned HTTP '.$status));
            }
            if ($status >= 400) {
                return $this->remember(PlugHealth::unavailable('Docling health returned HTTP '.$status));
            }

            return $this->remember(PlugHealth::available());
        } catch (TransportExceptionInterface $e) {
            return $this->remember(PlugHealth::unavailable($this->transportReason($e)));
        } catch (\Throwable $e) {
            return $this->remember(PlugHealth::unavailable($e->getMessage()));
        }
    }

    /**
     * @return array{text: string, markdown: string, pages: int|null}
     */
    public function convertFile(string $absolutePath): array
    {
        if (!$this->isEnabled()) {
            throw new DoclingUnavailableException('DOCLING_BASE_URL is unset or disabled');
        }
        if (!is_file($absolutePath) || 0 === filesize($absolutePath)) {
            throw new DoclingUnavailableException('Input file missing or empty');
        }

        try {
            // Symfony HttpClient encodes an array `body` as application/x-www-form-urlencoded.
            // docling-serve expects multipart/form-data with a real `files` part — a urlencoded
            // body yields HTTP 422 "Field required" at body.files while GET /health stays green.
            $formData = new FormDataPart([
                'files' => DataPart::fromPath($absolutePath, basename($absolutePath)),
                'to_formats' => 'md',
                'do_ocr' => 'true',
                'image_export_mode' => 'placeholder',
                'table_mode' => 'accurate',
            ]);
            $response = $this->httpClient->request('POST', $this->endpoint('/v1/convert/file'), [
                'timeout' => $this->timeoutMs / 1000,
                'headers' => array_merge($formData->getPreparedHeaders()->toArray(), [
                    'Accept' => 'application/json',
                    'User-Agent' => 'synaplan-docling-client',
                ]),
                'body' => $formData->bodyToIterable(),
            ]);
            $status = $response->getStatusCode();
            if ($status >= 500) {
                throw new DoclingUnavailableException('Docling convert returned HTTP '.$status);
            }
            if ($status >= 400) {
                throw new DoclingRejectedException('Docling convert returned HTTP '.$status);
            }

            $payload = $response->toArray(false);
        } catch (DoclingUnavailableException|DoclingRejectedException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {
            throw new DoclingUnavailableException($this->transportReason($e), $e);
        } catch (\Throwable $e) {
            throw new DoclingUnavailableException($e->getMessage(), $e);
        }

        $document = is_array($payload['document'] ?? null) ? $payload['document'] : [];
        $markdown = is_string($document['md_content'] ?? null) ? $document['md_content'] : '';
        $text = is_string($document['text_content'] ?? null) ? $document['text_content'] : '';
        if ('' === $text) {
            $text = $markdown;
        }

        $pages = $document['pages'] ?? $payload['pages'] ?? null;
        $pageCount = is_int($pages) ? $pages : (is_array($pages) ? count($pages) : null);

        $this->logger->info('Docling: convert succeeded', [
            'file' => basename($absolutePath),
            'bytes' => strlen($text),
            'markdown' => '' !== $markdown,
        ]);

        return [
            'text' => $text,
            'markdown' => $markdown,
            'pages' => $pageCount,
        ];
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    private function remember(PlugHealth $health): PlugHealth
    {
        $this->cachedHealth = $health;
        $this->cachedAt = time();

        return $health;
    }

    private function transportReason(TransportExceptionInterface $e): string
    {
        $message = $e->getMessage();
        if (str_contains(strtolower($message), 'timed out') || str_contains(strtolower($message), 'timeout')) {
            return 'Docling request timed out';
        }
        if (str_contains(strtolower($message), 'refused') || str_contains(strtolower($message), 'could not resolve')) {
            return 'Docling unavailable — connection refused';
        }

        return 'Docling unavailable: '.$message;
    }
}
