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
    public const KEY_WEB_SEARCH_USER_OVERRIDE_ALLOWED = 'WEB_SEARCH.USER_OVERRIDE_ALLOWED';
    public const KEY_WEB_SEARCH_TIMEOUT_MS = 'WEB_SEARCH.TIMEOUT_MS';
    public const KEY_WEB_SEARCH_MAX_CONTENT_CHARS = 'WEB_SEARCH.MAX_CONTENT_CHARS';
    public const KEY_RERANK_ENABLED = 'RERANK.ENABLED';
    public const KEY_RERANK_CANDIDATES_MULTIPLIER = 'RERANK.CANDIDATES_MULTIPLIER';
    public const KEY_RERANK_LATENCY_BUDGET_MS = 'RERANK.LATENCY_BUDGET_MS';
    public const KEY_RERANK_LLM_FALLBACK = 'RERANK.LLM_FALLBACK';
    public const KEY_RERANK_MAX_CANDIDATE_CHARS = 'RERANK.MAX_CANDIDATE_CHARS';
    public const KEY_RERANK_LAST_EVAL = 'RERANK.LAST_EVAL';

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
    public const DEFAULT_WEB_SEARCH_TIMEOUT_MS = 8000;
    public const DEFAULT_WEB_SEARCH_MAX_CONTENT_CHARS = 4000;
    public const DEFAULT_WEB_SEARCH_USER_OVERRIDE_ALLOWED = false;

    /** @var list<string> */
    public const WEB_SEARCH_PROVIDERS = [
        'brave',
        'searxng',
        'tavily',
        'exa',
        'firecrawl',
        'perplexity',
    ];

    public const DEFAULT_RERANK_ENABLED = false;
    public const DEFAULT_RERANK_MULTIPLIER = 4;
    public const DEFAULT_RERANK_LATENCY_MS = 800;
    public const DEFAULT_RERANK_LLM_FALLBACK = false;
    public const DEFAULT_RERANK_MAX_CANDIDATE_CHARS = 2000;
    public const MIN_RERANK_MULTIPLIER = 2;
    public const MAX_RERANK_MULTIPLIER = 10;
    public const MIN_RERANK_LATENCY_MS = 100;
    public const MAX_RERANK_LATENCY_MS = 5000;
    public const MAX_RERANK_STORAGE_CANDIDATES = 100;

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
        $global = $this->normalizeProviderKey(
            $this->readGlobal(self::KEY_WEB_SEARCH_PROVIDER, self::DEFAULT_WEB_SEARCH_PROVIDER),
            self::DEFAULT_WEB_SEARCH_PROVIDER,
        );

        if (null !== $userId && $userId > 0 && $this->isWebSearchUserOverrideAllowed()) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_WEB_SEARCH_PROVIDER);
            if (null !== $perUser && '' !== trim($perUser)) {
                $normalized = $this->normalizeProviderKey(strtolower(trim($perUser)), '');
                if ('' !== $normalized) {
                    return $normalized;
                }
            }
        }

        return $global;
    }

    public function webSearchFallback(): string
    {
        return $this->normalizeProviderKey($this->readGlobal(self::KEY_WEB_SEARCH_FALLBACK, ''), '');
    }

    public function isWebSearchUserOverrideAllowed(): bool
    {
        $raw = $this->readGlobal(self::KEY_WEB_SEARCH_USER_OVERRIDE_ALLOWED, '0');

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_WEB_SEARCH_USER_OVERRIDE_ALLOWED;
    }

    public function webSearchTimeoutMs(): int
    {
        return $this->readInt(self::KEY_WEB_SEARCH_TIMEOUT_MS, self::DEFAULT_WEB_SEARCH_TIMEOUT_MS);
    }

    public function webSearchMaxContentChars(): int
    {
        return $this->readInt(self::KEY_WEB_SEARCH_MAX_CONTENT_CHARS, self::DEFAULT_WEB_SEARCH_MAX_CONTENT_CHARS);
    }

    /**
     * Persist the instance-wide web-search selection. Unknown keys → InvalidArgumentException.
     */
    public function setWebSearch(string $active, string $fallback, bool $userOverrideAllowed): void
    {
        $activeKey = $this->requireKnownProvider($active);
        $fallbackKey = '' === trim($fallback) ? '' : $this->requireKnownProvider($fallback);

        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_WEB_SEARCH_PROVIDER, $activeKey);
        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_WEB_SEARCH_FALLBACK, $fallbackKey);
        $this->configRepository->setValue(
            0,
            self::CONFIG_GROUP,
            self::KEY_WEB_SEARCH_USER_OVERRIDE_ALLOWED,
            $userOverrideAllowed ? '1' : '0',
        );
    }

    /**
     * Per-user override. `null` clears the row. Forbidden unless override is allowed.
     */
    public function setUserWebSearchProvider(int $userId, ?string $provider): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('A positive user id is required');
        }
        if (!$this->isWebSearchUserOverrideAllowed()) {
            throw new \DomainException('User web search override is not allowed');
        }

        if (null === $provider || '' === trim($provider)) {
            $this->configRepository->deleteValue($userId, self::CONFIG_GROUP, self::KEY_WEB_SEARCH_PROVIDER);

            return;
        }

        $this->configRepository->setValue(
            $userId,
            self::CONFIG_GROUP,
            self::KEY_WEB_SEARCH_PROVIDER,
            $this->requireKnownProvider($provider),
        );
    }

    public function requireKnownProvider(string $key): string
    {
        $normalized = strtolower(trim($key));
        if (!\in_array($normalized, self::WEB_SEARCH_PROVIDERS, true)) {
            throw new \InvalidArgumentException('Unknown web search provider: '.$normalized);
        }

        return $normalized;
    }

    private function normalizeProviderKey(string $key, string $fallback): string
    {
        $normalized = strtolower(trim($key));
        if ('' === $normalized) {
            return $fallback;
        }
        if (!\in_array($normalized, self::WEB_SEARCH_PROVIDERS, true)) {
            return $fallback;
        }

        return $normalized;
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

    public function isRerankLlmFallback(): bool
    {
        $raw = $this->readGlobal(self::KEY_RERANK_LLM_FALLBACK, '0');

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_RERANK_LLM_FALLBACK;
    }

    public function rerankMaxCandidateChars(): int
    {
        return $this->readInt(self::KEY_RERANK_MAX_CANDIDATE_CHARS, self::DEFAULT_RERANK_MAX_CANDIDATE_CHARS);
    }

    /**
     * Persist instance-wide rerank settings. Does not write DEFAULTMODEL.RERANK.
     */
    public function setRerank(bool $enabled, int $multiplier, int $budgetMs, bool $llmFallback): void
    {
        if ($multiplier < self::MIN_RERANK_MULTIPLIER || $multiplier > self::MAX_RERANK_MULTIPLIER) {
            throw new \InvalidArgumentException(sprintf('multiplier must be between %d and %d', self::MIN_RERANK_MULTIPLIER, self::MAX_RERANK_MULTIPLIER));
        }
        if ($budgetMs < self::MIN_RERANK_LATENCY_MS || $budgetMs > self::MAX_RERANK_LATENCY_MS) {
            throw new \InvalidArgumentException(sprintf('budgetMs must be between %d and %d', self::MIN_RERANK_LATENCY_MS, self::MAX_RERANK_LATENCY_MS));
        }

        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_RERANK_ENABLED, $enabled ? '1' : '0');
        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_RERANK_CANDIDATES_MULTIPLIER, (string) $multiplier);
        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_RERANK_LATENCY_BUDGET_MS, (string) $budgetMs);
        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_RERANK_LLM_FALLBACK, $llmFallback ? '1' : '0');
    }

    /**
     * @return array{date: string, recallOff: float, recallOn: float, p95Off: float, p95On: float}|null
     */
    public function lastRerankEval(): ?array
    {
        $raw = $this->readGlobal(self::KEY_RERANK_LAST_EVAL, '');
        if ('' === $raw) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($decoded)
            || !\is_string($decoded['date'] ?? null)
            || !is_numeric($decoded['recallOff'] ?? null)
            || !is_numeric($decoded['recallOn'] ?? null)
            || !is_numeric($decoded['p95Off'] ?? null)
            || !is_numeric($decoded['p95On'] ?? null)
        ) {
            return null;
        }

        return [
            'date' => $decoded['date'],
            'recallOff' => (float) $decoded['recallOff'],
            'recallOn' => (float) $decoded['recallOn'],
            'p95Off' => (float) $decoded['p95Off'],
            'p95On' => (float) $decoded['p95On'],
        ];
    }

    /**
     * @param array{date: string, recallOff: float, recallOn: float, p95Off: float, p95On: float} $report
     */
    public function setLastRerankEval(array $report): void
    {
        $this->configRepository->setValue(
            0,
            self::CONFIG_GROUP,
            self::KEY_RERANK_LAST_EVAL,
            json_encode($report, JSON_THROW_ON_ERROR),
        );
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
