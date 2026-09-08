<?php

declare(strict_types=1);

namespace App\Plug\Extraction;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tagged `app.plug.extractor` adapters. `chain()` returns the configured
 * order for a MIME family; unknown keys are logged and skipped.
 */
final class ExtractionRegistry
{
    /** @var array<string, ContentExtractorInterface> */
    private array $byKey = [];

    /**
     * @param iterable<ContentExtractorInterface> $extractors
     */
    public function __construct(
        #[AutowireIterator('app.plug.extractor')]
        iterable $extractors,
        private readonly PlugConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($extractors as $extractor) {
            $this->byKey[$extractor->key()] = $extractor;
        }
    }

    /**
     * @return list<ContentExtractorInterface>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    public function byKey(string $key): ?ContentExtractorInterface
    {
        return $this->byKey[$key] ?? null;
    }

    /**
     * @return list<PlugDescriptor>
     */
    public function descriptors(): array
    {
        $out = [];
        foreach ($this->byKey as $extractor) {
            $out[] = $extractor->descriptor();
        }

        return $out;
    }

    /**
     * @return list<ContentExtractorInterface>
     */
    public function chain(string $family, bool $hasCloudStt = true): array
    {
        $resolved = [];
        foreach ($this->config->extractionChain($family, $hasCloudStt) as $key) {
            $adapter = $this->byKey[$key] ?? null;
            if (null === $adapter) {
                $this->logger->info('ExtractionRegistry: skipping unknown chain key', [
                    'family' => $family,
                    'key' => $key,
                ]);
                continue;
            }
            $resolved[] = $adapter;
        }

        return $resolved;
    }
}
