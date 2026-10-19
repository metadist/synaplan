<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Import\ModelDiscoveryService;
use App\AI\Import\ModelImportApplier;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\FreeApiRepository;
use App\Repository\ModelRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports the Demo aliases as model rows and moves the instance-wide
 * DEFAULTMODEL purposes onto them — and back.
 *
 * The rows are never deleted: BUSELOG keeps a foreign key to every model that
 * answered, so leaving deactivates them and a later activation turns them on
 * again. Only bindings that still point at a Demo row are restored; a purpose
 * the admin re-pointed while the Demo was active is left alone.
 */
final readonly class FreeApiModelBinder
{
    private const DEFAULT_GROUP = 'DEFAULTMODEL';
    private const MAX_NAME = 48;

    public function __construct(
        private ModelImportApplier $importer,
        private ModelRepository $models,
        private ConfigRepository $configRepository,
        private FreeApiRepository $repository,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<array{id: string, name: string}> $aliases the aliases the server offers this key
     *
     * @return array<string, int> alias => model id, active and selectable
     */
    public function importAliases(array $aliases): array
    {
        $rows = [];
        $names = [];
        foreach ($aliases as $alias) {
            $tag = FreeApiAliases::TAGS[$alias['id']] ?? null;
            if (null === $tag) {
                continue;
            }
            $names[$alias['id']] = mb_substr($alias['name'], 0, self::MAX_NAME);
            $rows[] = ['providerId' => $alias['id'], 'name' => $names[$alias['id']], 'tags' => [$tag], 'priceKnown' => true, 'priceIn' => 0.0, 'priceOut' => 0.0];
        }
        $this->importer->apply(ModelDiscoveryService::OPENAI_COMPATIBLE_PREFIX.FreeApiConfig::ENDPOINT_NAME, $rows);

        $ids = [];
        foreach ($this->demoModels() as $model) {
            $alias = $model->getProviderId();
            if (!isset($names[$alias]) || FreeApiAliases::TAGS[$alias] !== $model->getTag()) {
                continue;
            }
            $model->setActive(1)->setSelectable(1)->setName($names[$alias]);
            $ids[$alias] = (int) $model->getId();
        }
        $this->em->flush();

        return $ids;
    }

    /**
     * Instance-wide bindings of the purposes the Demo would take over.
     *
     * @return array<string, string|null> capability => stored value (null: no row)
     */
    public function snapshot(): array
    {
        $bindings = [];
        foreach (array_keys(FreeApiAliases::BINDINGS) as $capability) {
            $bindings[$capability] = $this->configRepository->getValue(0, self::DEFAULT_GROUP, $capability);
        }

        return $bindings;
    }

    /**
     * @param array<string, int> $aliasModelIds alias => model id
     *
     * @return list<string> capabilities now answered by the Demo
     */
    public function bind(array $aliasModelIds): array
    {
        $keepVectorize = $this->repository->hasStoredVectors();
        $bound = [];
        foreach (FreeApiAliases::BINDINGS as $capability => $alias) {
            if (!isset($aliasModelIds[$alias]) || (FreeApiAliases::VECTORIZE === $capability && $keepVectorize)) {
                continue;
            }
            $this->configRepository->setValue(0, self::DEFAULT_GROUP, $capability, (string) $aliasModelIds[$alias]);
            $bound[] = $capability;
        }

        return $bound;
    }

    /**
     * Puts back what the snapshot recorded, for every instance-wide purpose
     * that still points at a Demo model; members fall back to those defaults.
     *
     * @param array<string, string|null> $snapshot
     *
     * @return list<string> capabilities restored
     */
    public function restore(array $snapshot): array
    {
        $demoIds = $this->repository->modelIdsForEndpoint(OpenAiCompatibleEndpointRegistry::SERVICE, FreeApiConfig::ENDPOINT_NAME);
        $restored = [];
        foreach ($this->repository->bindingsTo($demoIds) as $binding) {
            if (0 !== $binding['ownerId']) {
                continue;
            }
            $capability = $binding['capability'];
            $previous = $snapshot[$capability] ?? null;
            if (null === $previous || \in_array((int) $previous, $demoIds, true)) {
                $this->configRepository->deleteValue(0, self::DEFAULT_GROUP, $capability);
            } else {
                $this->configRepository->setValue(0, self::DEFAULT_GROUP, $capability, $previous);
            }
            $restored[] = $capability;
        }
        $this->repository->deleteMemberBindingsTo($demoIds);

        return $restored;
    }

    /** Hides the Demo rows from every picker; they stay for the usage history. */
    public function deactivate(): void
    {
        foreach ($this->demoModels() as $model) {
            $model->setActive(0)->setSelectable(0);
        }
        $this->em->flush();
    }

    /**
     * @return list<Model>
     */
    private function demoModels(): array
    {
        $ids = $this->repository->modelIdsForEndpoint(OpenAiCompatibleEndpointRegistry::SERVICE, FreeApiConfig::ENDPOINT_NAME);

        return [] === $ids ? [] : $this->models->findBy(['id' => $ids]);
    }
}
