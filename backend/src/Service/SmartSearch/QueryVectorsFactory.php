<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

use App\Entity\User;
use App\Repository\SearchIndexRepository;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\SmartSearch\Index\SearchEmbeddingModel;
use App\Service\UserMemoryService;
use Psr\Log\LoggerInterface;

final readonly class QueryVectorsFactory
{
    public function __construct(
        private SearchEmbeddingModel $searchModel,
        private ModelConfigService $modelConfig,
        private UserMemoryService $memories,
        private SearchIndexRepository $index,
        private RateLimitService $rateLimits,
        private LoggerInterface $logger,
    ) {
    }

    public function create(User $user, string $query): QueryVectors
    {
        return new QueryVectors($user, $query, $this->searchModel, $this->modelConfig, $this->memories, $this->index, $this->rateLimits, $this->logger);
    }
}
