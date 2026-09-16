<?php

declare(strict_types=1);

namespace App\Service\File\Office;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Collabora CODE convert-to client (LibreOffice engine over HTTP).
 *
 * Same pattern as {@see \App\Service\File\TikaClient}: constructor DI, structured
 * logging, one-time health probe. Never throws to callers — failures return
 * null / []. Empty or "disabled" URL keeps today's behaviour.
 */
final class OfficeConverterClient
{
    public const TARGET_FORMATS = [
        'pdf',
        'png',
        'docx',
        'xlsx',
        'pptx',
        'odt',
        'ods',
        'odp',
        'csv',
        'html',
        'txt',
    ];

    /**
     * Convert option: render every sheet of a workbook as ONE PDF page sized
     * to its content (Collabora's `FullSheetPreview`, LibreOffice's
     * `SinglePageSheets` PDF filter option). Without it the Calc print layout
     * is used as-is: fixed page size, narrow first column, long row labels cut
     * at the cell border and repeated even narrower on continuation pages
     * (#1690). Ignored by Writer/Impress sources.
     */
    public const OPTION_FULL_SHEET_PREVIEW = 'full_sheet_preview';

    private const FORM_FIELD_FULL_SHEET_PREVIEW = 'FullSheetPreview';

    private bool $healthCheckDone = false;

    /** @var array<string, mixed>|null */
    private ?array $capabilitiesCache = null;

    private bool $capabilitiesFetched = false;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $officeConvertUrl,
        private int $officeConvertTimeoutMs,
    ) {
    }

    public function isEnabled(): bool
    {
        $url = trim($this->officeConvertUrl);

        return '' !== $url && 'disabled' !== $url;
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        if ($this->capabilitiesFetched) {
            return $this->capabilitiesCache ?? [];
        }

        $this->capabilitiesFetched = true;
        if (!$this->isEnabled()) {
            $this->capabilitiesCache = [];

            return [];
        }

        $endpoint = rtrim($this->officeConvertUrl, '/').'/hosting/capabilities';

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'timeout' => 3,
                'headers' => ['User-Agent' => 'synaplan-office-converter'],
            ]);
            if ($response->getStatusCode() >= 400) {
                $this->logger->warning('Office converter capabilities failed', [
                    'endpoint' => $endpoint,
                    'http_code' => $response->getStatusCode(),
                ]);
                $this->capabilitiesCache = [];

                return [];
            }

            $this->capabilitiesCache = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Office converter capabilities failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            $this->capabilitiesCache = [];
        }

        return $this->capabilitiesCache;
    }

    /**
     * Convert a file to $targetFormat. Writes the result next to the input
     * (NFS-visible) and returns the absolute path, or null on any failure.
     *
     * @param array<string, mixed> $options optional `lang`, {@see self::OPTION_FULL_SHEET_PREVIEW}
     */
    public function convert(string $absoluteInputPath, string $targetFormat, array $options = []): ?string
    {
        $format = strtolower($targetFormat);
        if (!in_array($format, self::TARGET_FORMATS, true)) {
            $this->logger->warning('Office converter: unsupported format', [
                'format' => $targetFormat,
                'file' => basename($absoluteInputPath),
            ]);

            return null;
        }

        if (!$this->isEnabled()) {
            return null;
        }

        $this->maybePingHealth();

        $size = is_file($absoluteInputPath) ? filesize($absoluteInputPath) : false;
        if (false === $size || 0 === $size) {
            $this->logger->warning('Office converter: input file missing or empty', [
                'file' => $absoluteInputPath,
                'format' => $format,
            ]);

            return null;
        }

        $endpoint = rtrim($this->officeConvertUrl, '/').'/cool/convert-to/'.$format;
        $retried = false;
        $startTs = microtime(true);

        while (true) {
            try {
                return $this->doConvert($absoluteInputPath, $format, $options, $endpoint, $startTs);
            } catch (HttpExceptionInterface $e) {
                $this->logFailure($endpoint, $format, $startTs, $e, $e->getResponse()->getStatusCode());

                return null;
            } catch (\Throwable $e) {
                $this->logFailure($endpoint, $format, $startTs, $e, null);
                if (!$retried) {
                    $retried = true;
                    continue;
                }

                return null;
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function doConvert(string $absoluteInputPath, string $format, array $options, string $endpoint, float $startTs): ?string
    {
        $fields = [
            'data' => DataPart::fromPath($absoluteInputPath),
        ];
        if (isset($options['lang']) && is_string($options['lang']) && '' !== $options['lang']) {
            $fields['lang'] = $options['lang'];
        }
        if (true === ($options[self::OPTION_FULL_SHEET_PREVIEW] ?? false)) {
            $fields[self::FORM_FIELD_FULL_SHEET_PREVIEW] = 'true';
        }

        $formData = new FormDataPart($fields);
        $response = $this->httpClient->request('POST', $endpoint, [
            'headers' => array_merge($formData->getPreparedHeaders()->toArray(), [
                'User-Agent' => 'synaplan-office-converter',
            ]),
            'body' => $formData->bodyToIterable(),
            'timeout' => max(1, $this->officeConvertTimeoutMs) / 1000,
        ]);

        $statusCode = $response->getStatusCode();
        $elapsedMs = (int) ((microtime(true) - $startTs) * 1000);

        if ($statusCode >= 400) {
            $this->logger->warning('Office converter HTTP error', [
                'endpoint' => $endpoint,
                'format' => $format,
                'elapsed_ms' => $elapsedMs,
                'http_code' => $statusCode,
            ]);

            return null;
        }

        $body = $response->getContent();
        if ('' === $body) {
            $this->logger->warning('Office converter empty body', [
                'endpoint' => $endpoint,
                'format' => $format,
                'elapsed_ms' => $elapsedMs,
                'http_code' => $statusCode,
            ]);

            return null;
        }

        $dir = dirname($absoluteInputPath);
        $base = pathinfo($absoluteInputPath, PATHINFO_FILENAME);
        $outputPath = $dir.'/'.$base.'.convert-'.bin2hex(random_bytes(4)).'.'.$format;
        if (false === file_put_contents($outputPath, $body)) {
            $this->logger->warning('Office converter failed to write output', [
                'endpoint' => $endpoint,
                'format' => $format,
                'output' => $outputPath,
            ]);

            return null;
        }

        $this->logger->info('Office converter success', [
            'endpoint' => $endpoint,
            'format' => $format,
            'elapsed_ms' => $elapsedMs,
            'http_code' => $statusCode,
            'bytes' => strlen($body),
        ]);

        return $outputPath;
    }

    private function maybePingHealth(): void
    {
        if ($this->healthCheckDone) {
            return;
        }
        $this->healthCheckDone = true;
        $this->capabilities();
    }

    private function logFailure(string $endpoint, string $format, float $startTs, \Throwable $e, ?int $httpCode): void
    {
        $this->logger->warning('Office converter attempt failed', [
            'endpoint' => $endpoint,
            'format' => $format,
            'elapsed_ms' => (int) ((microtime(true) - $startTs) * 1000),
            'http_code' => $httpCode,
            'error' => $e->getMessage(),
        ]);
    }
}
