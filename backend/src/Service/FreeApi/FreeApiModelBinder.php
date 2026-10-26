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
     * @return array<string, array<string, int>> alias => tag => model id, active and selectable
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
            $tags = array_values(array_unique([$tag, ...FreeApiAliases::ADDITIONAL_TAGS[$alias['id']] ?? []]));
            $rows[] = ['providerId' => $alias['id'], 'name' => $names[$alias['id']], 'tags' => $tags, 'priceKnown' => true, 'priceIn' => 0.0, 'priceOut' => 0.0];
        }
        $this->importer->apply(ModelDiscoveryService::OPENAI_COMPATIBLE_PREFIX.FreeApiConfig::ENDPOINT_NAME, $rows);

        $ids = [];
        foreach ($this->demoModels() as $model) {
            $alias = $model->getProviderId();
            if (!isset($names[$alias]) || !\in_array($model->getTag(), $this->tagsFor($alias), true)) {
                continue;
            }
            $model->setActive(1)->setSelectable(1)->setName($names[$alias]);
            $ids[$alias][$model->getTag()] = (int) $model->getId();
        }
        $this->em->flush();

        return $ids;
    }

    /**
     * An install that turned the Demo on before the memory-extraction row
     * existed still has MEM pointing at the chat-tagged sorter. Re-import the
     * extra tag and move that binding, and only that binding.
     */
    public function realignPurposeRows(): void
    {
        $aliases = [];
        foreach ($this->demoModels() as $model) {
            $alias = $model->getProviderId();
            if (!isset(FreeApiAliases::TAGS[$alias])) {
                continue;
            }
            $aliases[$alias] = ['id' => $alias, 'name' => $model->getName()];
        }
        if ([] === $aliases) {
            return;
        }

        $this->moveLegacyBindings($this->importAliases(array_values($aliases)));
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
     * @param array<string, array<string, int>> $aliasModelIds alias => tag => model id
     *
     * @return list<string> capabilities now answered by the Demo
     */
    public function bind(array $aliasModelIds): array
    {
        $keepVectorize = $this->repository->hasStoredVectors();
        $bound = [];
        foreach (FreeApiAliases::BINDINGS as $capability => $alias) {
            $modelId = $this->modelIdFor($aliasModelIds, $capability, $alias);
            if (null === $modelId || (FreeApiAliases::VECTORIZE === $capability && $keepVectorize)) {
                continue;
            }
            $this->configRepository->setValue(0, self::DEFAULT_GROUP, $capability, (string) $modelId);
            $bound[] = $capability;
        }

        return $bound;
    }

    /**
     * @param array<string, array<string, int>> $aliasModelIds
     */
    private function moveLegacyBindings(array $aliasModelIds): void
    {
        foreach (FreeApiAliases::BINDING_TAGS as $capability => $tag) {
            $alias = FreeApiAliases::BINDINGS[$capability];
            $wantedId = $aliasModelIds[$alias][$tag] ?? null;
            $legacyId = $aliasModelIds[$alias][FreeApiAliases::TAGS[$alias]] ?? null;
            if (null === $wantedId || null === $legacyId) {
                continue;
            }
            $current = $this->configRepository->getValue(0, self::DEFAULT_GROUP, $capability);
            if ((string) $legacyId === (string) $current) {
                $this->configRepository->setValue(0, self::DEFAULT_GROUP, $capability, (string) $wantedId);
            }
        }
    }

    /**
     * @param array<string, array<string, int>> $aliasModelIds
     */
    private function modelIdFor(array $aliasModelIds, string $capability, string $alias): ?int
    {
        $tag = FreeApiAliases::BINDING_TAGS[$capability] ?? FreeApiAliases::TAGS[$alias];

        return $aliasModelIds[$alias][$tag] ?? null;
    }

    /**
     * @return list<string>
     */
    private function tagsFor(string $alias): array
    {
        $primary = FreeApiAliases::TAGS[$alias] ?? null;
        if (null === $primary) {
            return [];
        }

        return array_values(array_unique([$primary, ...FreeApiAliases::ADDITIONAL_TAGS[$alias] ?? []]));
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
