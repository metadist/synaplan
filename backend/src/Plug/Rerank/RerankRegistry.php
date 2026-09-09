<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\Rerank\Adapter\HttpRerankAdapter;
use App\Plug\Rerank\Adapter\LlmReranker;
use App\Service\ModelConfigService;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tagged `app.plug.rerank` adapters.
 *
 * `active()` is null while PLUGS.RERANK.ENABLED is 0, or when nothing is
 * bound and LLM fallback is off.
 */
final class RerankRegistry
{
    /** @var array<string, RerankProviderInterface> */
    private array $byKey = [];

    /**
     * @param iterable<RerankProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.plug.rerank')]
        iterable $providers,
        private readonly PlugConfigService $config,
        private readonly ModelConfigService $modelConfig,
    ) {
        foreach ($providers as $provider) {
            $this->byKey[$provider->key()] = $provider;
        }
    }

    /**
     * @return list<RerankProviderInterface>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    public function byKey(string $key): ?RerankProviderInterface
    {
        return $this->byKey[$key] ?? null;
    }

    /**
     * @return list<PlugDescriptor>
     */
    public function descriptors(): array
    {
        $out = [];
        foreach ($this->byKey as $provider) {
            $out[] = $provider->descriptor();
        }

        return $out;
    }

    public function active(): ?RerankProviderInterface
    {
        if (!$this->config->isRerankEnabled()) {
            return null;
        }

        $bound = $this->modelConfig->getDefaultModel('RERANK');
        if (null !== $bound) {
            $http = $this->byKey[HttpRerankAdapter::KEY] ?? null;
            if (null !== $http && $http->health()->available) {
                return $http;
            }

            return null;
        }

        if ($this->config->isRerankLlmFallback()) {
            $llm = $this->byKey[LlmReranker::KEY] ?? null;
            if (null !== $llm && $llm->health()->available) {
                return $llm;
            }
        }

        return null;
    }
}
