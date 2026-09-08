<?php

declare(strict_types=1);

namespace App\Plug;

use App\Plug\Extraction\ExtractionRegistry;
use App\Repository\ConfigRepository;

/**
 * Reads BCONFIG group {@see self::CONFIG_GROUP}. Owner 0 is instance-wide;
 * WEB_SEARCH.PROVIDER also accepts a per-user override (master plan §12.2).
 *
 * Seeded values reproduce today's FileProcessor order and Brave-only search.
 * Changing a seeder value does not propagate — ship a migration to roll out
 * a new default (house rule).
 */
final readonly class PlugConfigService
{
    public const CONFIG_GROUP = 'PLUGS';

    public const KEY_CHAIN_TEXT = 'EXTRACTION.CHAIN.text';
    public const KEY_CHAIN_DOCUMENT = 'EXTRACTION.CHAIN.document';
    public const KEY_CHAIN_IMAGE = 'EXTRACTION.CHAIN.image';
    public const KEY_CHAIN_AUDIO = 'EXTRACTION.CHAIN.audio';
    public const KEY_CHAIN_AUDIO_NO_CLOUD = 'EXTRACTION.CHAIN.audio_no_cloud';
    public const KEY_CHAIN_VIDEO = 'EXTRACTION.CHAIN.video';
    public const KEY_QUALITY_MIN_LENGTH = 'EXTRACTION.QUALITY.min_length';
    public const KEY_QUALITY_MIN_ENTROPY = 'EXTRACTION.QUALITY.min_entropy';
    public const KEY_QUALITY_APPLY_TO = 'EXTRACTION.QUALITY.apply_to';
    public const KEY_WEB_SEARCH_PROVIDER = 'WEB_SEARCH.PROVIDER';
    public const KEY_WEB_SEARCH_FALLBACK = 'WEB_SEARCH.FALLBACK';
    public const KEY_RERANK_ENABLED = 'RERANK.ENABLED';
    public const KEY_RERANK_CANDIDATES_MULTIPLIER = 'RERANK.CANDIDATES_MULTIPLIER';
    public const KEY_RERANK_LATENCY_BUDGET_MS = 'RERANK.LATENCY_BUDGET_MS';

    public const DEFAULT_CHAIN_TEXT = 'native';
    public const DEFAULT_CHAIN_DOCUMENT = 'structured_office,office_convert,tika,pdf_vision';
    public const DEFAULT_CHAIN_IMAGE = 'vision';
    public const DEFAULT_CHAIN_AUDIO = 'stt_cloud,whisper_local';
    public const DEFAULT_CHAIN_AUDIO_NO_CLOUD = 'whisper_local,stt_cloud';
    public const DEFAULT_CHAIN_VIDEO = 'video_analysis';
    public const DEFAULT_MIN_LENGTH = 10;
    public const DEFAULT_MIN_ENTROPY = 3.0;
    public const DEFAULT_QUALITY_APPLY_TO = 'pdf';

    /** @var list<string> */
    public const FAMILIES = ['text', 'document', 'image', 'audio', 'audio_no_cloud', 'video'];
    public const DEFAULT_WEB_SEARCH_PROVIDER = 'brave';
    public const DEFAULT_RERANK_ENABLED = false;
    public const DEFAULT_RERANK_MULTIPLIER = 4;
    public const DEFAULT_RERANK_LATENCY_MS = 800;

    /** Built-in extraction keys FileProcessor already implements. */
    public const BUILTIN_EXTRACTOR_KEYS = [
        'native',
        'structured_office',
        'office_convert',
        'tika',
        'pdf_vision',
        'vision',
        'stt_cloud',
        'whisper_local',
        'video_analysis',
    ];

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * Ordered adapter keys for a MIME family. Unknown keys are the caller's
     * problem to skip; this method only splits and trims.
     *
     * @return list<string>
     */
    public function extractionChain(string $family, bool $hasCloudStt = true): array
    {
        $key = match ($family) {
            'text' => self::KEY_CHAIN_TEXT,
            'document' => self::KEY_CHAIN_DOCUMENT,
            'image' => self::KEY_CHAIN_IMAGE,
            'audio' => $hasCloudStt ? self::KEY_CHAIN_AUDIO : self::KEY_CHAIN_AUDIO_NO_CLOUD,
            'video' => self::KEY_CHAIN_VIDEO,
            default => self::KEY_CHAIN_DOCUMENT,
        };
        $default = match ($family) {
            'text' => self::DEFAULT_CHAIN_TEXT,
            'document' => self::DEFAULT_CHAIN_DOCUMENT,
            'image' => self::DEFAULT_CHAIN_IMAGE,
            'audio' => $hasCloudStt ? self::DEFAULT_CHAIN_AUDIO : self::DEFAULT_CHAIN_AUDIO_NO_CLOUD,
            'video' => self::DEFAULT_CHAIN_VIDEO,
            default => self::DEFAULT_CHAIN_DOCUMENT,
        };

        return $this->splitList($this->readGlobal($key, $default));
    }

    /**
     * Adapter keys in the family chain that FileProcessor does not already
     * run (S2 Docling lands here). Empty in S1.
     *
     * @return list<string>
     */
    public function extraExtractorKeys(string $family, bool $hasCloudStt = true): array
    {
        $extras = [];
        foreach ($this->extractionChain($family, $hasCloudStt) as $key) {
            if (!\in_array($key, self::BUILTIN_EXTRACTOR_KEYS, true)) {
                $extras[] = $key;
            }
        }

        return $extras;
    }

    public function qualityMinLength(): int
    {
        return $this->readInt(self::KEY_QUALITY_MIN_LENGTH, self::DEFAULT_MIN_LENGTH);
    }

    public function qualityMinEntropy(): float
    {
        return $this->readFloat(self::KEY_QUALITY_MIN_ENTROPY, self::DEFAULT_MIN_ENTROPY);
    }

    /**
     * Extensions / families the quality gate applies to. Seeded `pdf`.
     *
     * @return list<string>
     */
    public function qualityApplyTo(): array
    {
        return $this->splitList($this->readGlobal(self::KEY_QUALITY_APPLY_TO, self::DEFAULT_QUALITY_APPLY_TO));
    }

    /**
     * @return array<string, list<string>>
     */
    public function allChains(): array
    {
        return [
            'text' => $this->extractionChain('text'),
            'document' => $this->extractionChain('document'),
            'image' => $this->extractionChain('image'),
            'audio' => $this->extractionChain('audio', true),
            'audio_no_cloud' => $this->extractionChain('audio', false),
            'video' => $this->extractionChain('video'),
        ];
    }

    /**
     * @return list<string>
     */
    public function knownExtractorKeys(ExtractionRegistry $registry): array
    {
        $keys = self::BUILTIN_EXTRACTOR_KEYS;
        foreach ($registry->all() as $adapter) {
            $keys[] = $adapter->key();
        }

        return array_values(array_unique($keys));
    }

    /**
     * Persist one family's adapter order. Unknown family or key → InvalidArgumentException.
     *
     * @param list<mixed>  $keys
     * @param list<string> $knownKeys
     */
    public function setChain(string $family, array $keys, array $knownKeys): void
    {
        $setting = $this->chainSetting($family);
        $normalized = [];
        foreach ($keys as $key) {
            if (!\is_string($key)) {
                throw new \InvalidArgumentException('Extractor keys must be strings');
            }
            $trimmed = strtolower(trim($key));
            if ('' === $trimmed) {
                continue;
            }
            if (!\in_array($trimmed, $knownKeys, true)) {
                throw new \InvalidArgumentException('Unknown extractor key: '.$trimmed);
            }
            $normalized[] = $trimmed;
        }

        $this->configRepository->setValue(0, self::CONFIG_GROUP, $setting, implode(',', $normalized));
    }

    /**
     * @param array<mixed, mixed> $chains
     * @param list<string>        $knownKeys
     */
    public function setChains(array $chains, array $knownKeys): void
    {
        foreach ($chains as $family => $keys) {
            if (!\is_string($family)) {
                throw new \InvalidArgumentException('Unknown extraction family');
            }
            if (!\is_array($keys)) {
                throw new \InvalidArgumentException('Chain for '.$family.' must be a list of keys');
            }
            $this->setChain($family, $keys, $knownKeys);
        }
    }

    private function chainSetting(string $family): string
    {
        return match ($family) {
            'text' => self::KEY_CHAIN_TEXT,
            'document' => self::KEY_CHAIN_DOCUMENT,
            'image' => self::KEY_CHAIN_IMAGE,
            'audio' => self::KEY_CHAIN_AUDIO,
            'audio_no_cloud' => self::KEY_CHAIN_AUDIO_NO_CLOUD,
            'video' => self::KEY_CHAIN_VIDEO,
            default => throw new \InvalidArgumentException('Unknown extraction family: '.$family),
        };
    }

    public function webSearchProvider(?int $userId): string
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_WEB_SEARCH_PROVIDER);
            if (null !== $perUser && '' !== trim($perUser)) {
                return strtolower(trim($perUser));
            }
        }

        return strtolower($this->readGlobal(self::KEY_WEB_SEARCH_PROVIDER, self::DEFAULT_WEB_SEARCH_PROVIDER));
    }

    public function webSearchFallback(): string
    {
        return strtolower($this->readGlobal(self::KEY_WEB_SEARCH_FALLBACK, ''));
    }

    public function isRerankEnabled(): bool
    {
        $raw = $this->readGlobal(self::KEY_RERANK_ENABLED, '0');

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_RERANK_ENABLED;
    }

    public function rerankCandidatesMultiplier(): int
    {
        return $this->readInt(self::KEY_RERANK_CANDIDATES_MULTIPLIER, self::DEFAULT_RERANK_MULTIPLIER);
    }

    public function rerankLatencyBudgetMs(): int
    {
        return $this->readInt(self::KEY_RERANK_LATENCY_BUDGET_MS, self::DEFAULT_RERANK_LATENCY_MS);
    }

    private function readGlobal(string $setting, string $default): string
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);

        return null !== $value ? $value : $default;
    }

    private function readInt(string $setting, int $default): int
    {
        $raw = trim($this->readGlobal($setting, (string) $default));
        if ('' === $raw || !is_numeric($raw)) {
            return $default;
        }

        return (int) $raw;
    }

    private function readFloat(string $setting, float $default): float
    {
        $raw = trim($this->readGlobal($setting, (string) $default));
        if ('' === $raw || !is_numeric($raw)) {
            return $default;
        }

        return (float) $raw;
    }

    /**
     * @return list<string>
     */
    private function splitList(string $csv): array
    {
        $keys = [];
        foreach (explode(',', $csv) as $part) {
            $key = strtolower(trim($part));
            if ('' !== $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
