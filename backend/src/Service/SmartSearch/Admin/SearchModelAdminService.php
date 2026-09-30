<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Admin;

use App\Entity\RevectorizeRun;
use App\Entity\User;
use App\Message\ReVectorizeMessage;
use App\Repository\RevectorizeRunRepository;
use App\Repository\SearchIndexRepository;
use App\Service\SmartSearch\Index\SearchEmbeddingModel;
use App\Service\SmartSearch\Interpret\SearchInterpreter;
use App\Service\SmartSearch\SearchModelConfigService;
use App\Service\SmartSearch\SmartSearchConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * What the Smart Search card on AI infrastructure shows and changes. The AI
 * model switches at once; the embedding model is probed, then the index
 * moves onto it in a reindex run, and a failed run puts the old one back.
 */
final readonly class SearchModelAdminService
{
    public const SLOT_KEYS = [
        'ai' => SearchModelConfigService::SLOT_AI,
        'embed' => SearchModelConfigService::SLOT_EMBED,
    ];

    public function __construct(
        private SearchModelConfigService $searchModels,
        private SearchEmbeddingModel $embeddingModel,
        private SearchIndexRepository $index,
        private RevectorizeRunRepository $runs,
        private SmartSearchConfig $config,
        private SearchInterpreter $interpreter,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(User $admin): array
    {
        $embedModelId = $this->searchModels->embedModelId();
        $coverage = $this->index->embeddingCoverage($embedModelId);
        $active = $this->runs->findActive();
        $latest = $this->runs->findLatestForScope(RevectorizeRun::SCOPE_SEARCH);

        return [
            'ai' => $this->slot(SearchModelConfigService::SLOT_AI),
            'embed' => $this->slot(SearchModelConfigService::SLOT_EMBED),
            'aiEnabled' => $this->config->isAiEnabled(null),
            'aiAvailable' => $this->interpreter->isAvailable($admin),
            'index' => [
                'rows' => $coverage['rows'],
                'embeddedRows' => $coverage['embedded'],
                'semanticAvailable' => null !== $embedModelId && $coverage['embedded'] > 0,
            ],
            'activeRun' => null !== $active && RevectorizeRun::SCOPE_SEARCH === $active->getScope() ? self::run($active) : null,
            'otherRunActive' => null !== $active && RevectorizeRun::SCOPE_SEARCH !== $active->getScope(),
            'latestRun' => null !== $latest ? self::run($latest) : null,
        ];
    }

    /**
     * @return array{previousModelId: ?int, runId: ?int}
     *
     * @throws SearchModelChangeException
     */
    public function change(User $admin, string $slotKey, ?int $modelId): array
    {
        $slot = self::SLOT_KEYS[$slotKey] ?? throw new SearchModelChangeException(SearchModelChangeException::INVALID_MODEL, sprintf('Unknown slot "%s".', $slotKey));
        $previous = $this->searchModels->selectedModelId($slot);

        if (null !== $modelId) {
            try {
                $this->searchModels->requireSelectable($slot, $modelId);
            } catch (\InvalidArgumentException $e) {
                throw new SearchModelChangeException(SearchModelChangeException::INVALID_MODEL, $e->getMessage());
            }
        }

        if (SearchModelConfigService::SLOT_AI === $slot) {
            $this->searchModels->store($slot, $modelId);

            return ['previousModelId' => $previous, 'runId' => null];
        }

        return ['previousModelId' => $previous, 'runId' => $this->switchEmbedding($admin, $modelId)];
    }

    private function switchEmbedding(User $admin, ?int $modelId): ?int
    {
        $from = $this->searchModels->embedModelId();
        $to = $modelId ?? $this->searchModels->inheritedModelId(SearchModelConfigService::SLOT_EMBED);
        if ($to === $from) {
            $this->searchModels->store(SearchModelConfigService::SLOT_EMBED, $modelId);

            return null;
        }

        if (null !== $this->runs->findActive()) {
            throw new SearchModelChangeException(SearchModelChangeException::RUN_IN_PROGRESS, 'A reindex run is already in progress.');
        }
        $target = null === $to ? null : $this->searchModels->requireSelectable(SearchModelConfigService::SLOT_EMBED, $to);
        if (null === $target || !$this->embeddingModel->probe($target)) {
            throw new SearchModelChangeException(SearchModelChangeException::PROBE_FAILED, sprintf('Embedding model %s did not answer a test call.', $to ?? 'none'));
        }

        $this->searchModels->store(SearchModelConfigService::SLOT_EMBED, $modelId);
        $coverage = $this->index->embeddingCoverage($to);
        $run = (new RevectorizeRun())
            ->setUserId($admin->getId() ?? 0)
            ->setScope(RevectorizeRun::SCOPE_SEARCH)
            ->setModelFromId($from ?? 0)
            ->setModelToId($to)
            ->setStatus(RevectorizeRun::STATUS_QUEUED)
            ->setChunksTotal($coverage['rows'] - $coverage['embedded'])
            ->setSeverity(RevectorizeRun::SEVERITY_INFO);
        $this->runs->save($run);
        $this->bus->dispatch(new ReVectorizeMessage($run->getId() ?? 0));

        $this->logger->info('Admin: Smart Search embedding model switched, reindex queued', [
            'user_id' => $admin->getId(),
            'from' => $from,
            'to' => $to,
            'run_id' => $run->getId(),
        ]);

        return $run->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(string $slot): array
    {
        $inherited = $this->searchModels->inheritedModelId($slot);
        $effective = $this->searchModels->effectiveModelId($slot);

        return [
            'selectedModelId' => $this->searchModels->selectedModelId($slot),
            'inheritedModelId' => $inherited,
            'inheritedModelName' => $this->searchModels->label($inherited),
            'effectiveModelId' => $effective,
            'effectiveModelName' => $this->searchModels->label($effective),
            'options' => $this->searchModels->options($slot),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function run(RevectorizeRun $run): array
    {
        return [
            'id' => $run->getId(),
            'status' => $run->getStatus(),
            'fromModelId' => $run->getModelFromId(),
            'toModelId' => $run->getModelToId(),
            'rowsTotal' => $run->getChunksTotal(),
            'rowsProcessed' => $run->getChunksProcessed(),
            'rowsFailed' => $run->getChunksFailed(),
            'finishedAt' => $run->getFinishedAt(),
        ];
    }
}
