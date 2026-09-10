<?php

declare(strict_types=1);

namespace App\Plug\Extraction;

use App\Plug\PlugConfigService;

/**
 * Status + chain writes for the admin Extraction tab.
 */
final readonly class ExtractionAdminService
{
    private const BUILTIN_LABELS = [
        'native' => 'Native text',
        'structured_office' => 'Structured Office',
        'office_convert' => 'Office convert',
        'tika' => 'Apache Tika',
        'pdf_vision' => 'PDF vision fallback',
        'vision' => 'Vision',
        'stt_cloud' => 'Cloud speech-to-text',
        'whisper_local' => 'Local Whisper',
        'video_analysis' => 'Video analysis',
    ];

    public function __construct(
        private ExtractionRegistry $registry,
        private PlugConfigService $plugConfig,
        private ExtractionProbeService $probe,
    ) {
    }

    /**
     * @return array{
     *     adapters: list<array{key: string, label: string, docsUrl: string, sovereignty: string, health: array{available: bool, reason: string|null}}>,
     *     chains: array<string, list<string>>,
     *     quality: array{minLength: int, minEntropy: float, applyTo: list<string>}
     * }
     */
    public function status(): array
    {
        return [
            'adapters' => $this->adapters(),
            'chains' => $this->plugConfig->allChains(),
            'quality' => [
                'minLength' => $this->plugConfig->qualityMinLength(),
                'minEntropy' => $this->plugConfig->qualityMinEntropy(),
                'applyTo' => $this->plugConfig->qualityApplyTo(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $chains
     *
     * @return array{
     *     adapters: list<array{key: string, label: string, docsUrl: string, sovereignty: string, health: array{available: bool, reason: string|null}}>,
     *     chains: array<string, list<string>>,
     *     quality: array{minLength: int, minEntropy: float, applyTo: list<string>}
     * }
     */
    public function setChains(array $chains): array
    {
        $this->plugConfig->setChains($chains, $this->plugConfig->knownExtractorKeys($this->registry));

        return $this->status();
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
        return $this->probe->testFile($absolutePath, $originalName, $userId);
    }

    /**
     * @return list<array{key: string, label: string, docsUrl: string, sovereignty: string, health: array{available: bool, reason: string|null}}>
     */
    private function adapters(): array
    {
        $seen = [];
        $out = [];

        foreach (PlugConfigService::BUILTIN_EXTRACTOR_KEYS as $key) {
            $adapter = $this->registry->byKey($key);
            $out[] = null !== $adapter ? $this->serializeAdapter($adapter) : [
                'key' => $key,
                'label' => self::BUILTIN_LABELS[$key],
                'docsUrl' => '',
                'sovereignty' => 'built-in',
                'health' => ['available' => true, 'reason' => null],
            ];
            $seen[$key] = true;
        }

        foreach ($this->registry->all() as $adapter) {
            if (isset($seen[$adapter->key()])) {
                continue;
            }
            $out[] = $this->serializeAdapter($adapter);
        }

        return $out;
    }

    /**
     * @return array{key: string, label: string, docsUrl: string, sovereignty: string, health: array{available: bool, reason: string|null}}
     */
    private function serializeAdapter(ContentExtractorInterface $adapter): array
    {
        $descriptor = $adapter->descriptor();
        $health = $adapter->health();

        return [
            'key' => $adapter->key(),
            'label' => $descriptor->label,
            'docsUrl' => $descriptor->docsUrl,
            'sovereignty' => $descriptor->sovereignty,
            'health' => [
                'available' => $health->available,
                'reason' => $health->reason,
            ],
        ];
    }
}
