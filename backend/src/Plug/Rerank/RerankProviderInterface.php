<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;

/**
 * One rerank adapter. S1 ships the port only — {@see RerankRegistry::active()}
 * is null while PLUGS.RERANK.ENABLED is 0.
 */
interface RerankProviderInterface
{
    public function key(): string;

    public function descriptor(): PlugDescriptor;

    /**
     * @param list<RerankCandidate> $candidates
     */
    public function rerank(string $query, array $candidates, int $topK, RerankOptions $options): RerankResult;

    public function health(): PlugHealth;
}
