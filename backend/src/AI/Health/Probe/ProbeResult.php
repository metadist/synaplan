<?php

declare(strict_types=1);

namespace App\AI\Health\Probe;

use App\AI\Health\FailureKind;

/**
 * What a free catalog lookup found for one provider.
 *
 * {@see self::skipped()} exists so a self-hosted install with a single API key
 * does not light up red for the fifteen providers it never configured. A
 * provider nobody set up is not broken, and saying so is the difference between
 * a status page an operator trusts and one they learn to ignore.
 */
final readonly class ProbeResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    /**
     * @param list<string> $modelIds             provider model ids the provider currently offers,
     *                                           lowercased; empty when the provider has no listing
     *                                           endpoint but did confirm the credentials
     * @param bool         $listingComplete      whether $modelIds is a real listing rather than a
     *                                           bare reachability check
     * @param bool         $listingAuthoritative whether absence from this listing is by itself
     *                                           conclusive. True only where one endpoint enumerates
     *                                           the provider's entire surface (Ollama's pulled
     *                                           models, Cloudflare's model search). False for every
     *                                           cloud catalog: those publish partial lists, so a
     *                                           missing model has to be confirmed one by one via
     *                                           {@see ModelListProbeInterface::confirm()}
     */
    private function __construct(
        public string $status,
        public array $modelIds,
        public bool $listingComplete,
        public bool $listingAuthoritative,
        public ?FailureKind $kind,
        public string $message,
    ) {
    }

    /**
     * @param list<string> $modelIds
     */
    public static function ok(array $modelIds, bool $listingAuthoritative = false): self
    {
        return new self(self::STATUS_OK, array_values(array_unique($modelIds)), true, $listingAuthoritative, null, '');
    }

    /**
     * The provider answered, but cannot enumerate its catalog. Proves the
     * credentials work; says nothing about individual models.
     */
    public static function reachable(string $message = 'Provider reachable, catalog listing not supported'): self
    {
        return new self(self::STATUS_OK, [], false, false, null, $message);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::STATUS_SKIPPED, [], false, false, null, $reason);
    }

    public static function failed(FailureKind $kind, string $message): self
    {
        return new self(self::STATUS_FAILED, [], false, false, $kind, $message);
    }

    public function isOk(): bool
    {
        return self::STATUS_OK === $this->status;
    }

    public function isSkipped(): bool
    {
        return self::STATUS_SKIPPED === $this->status;
    }

    public function isFailed(): bool
    {
        return self::STATUS_FAILED === $this->status;
    }

    /**
     * Does the provider still offer this model id?
     *
     * Comparison ignores case and the Ollama/Google style suffixes and prefixes
     * that differ between a catalog entry and the listing response.
     */
    public function offers(string $providerModelId): bool
    {
        $wanted = mb_strtolower(trim($providerModelId));
        if ('' === $wanted) {
            return false;
        }

        foreach ($this->modelIds as $offered) {
            if ($offered === $wanted) {
                return true;
            }
            // Ollama reports "llama3:latest" for a catalog entry of "llama3";
            // Google reports "models/gemini-3-pro" for "gemini-3-pro".
            if ($offered === $wanted.':latest' || $wanted === $offered.':latest') {
                return true;
            }
            if (str_ends_with($offered, '/'.$wanted)) {
                return true;
            }
        }

        return false;
    }
}
