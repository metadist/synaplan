<?php

declare(strict_types=1);

namespace App\AI\Import;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Entity\Model;
use App\Repository\ModelRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes discovered models into BMODELS, one row per capability tag.
 *
 * Idempotent by contract (C6): a row is created only when no
 * (service, tag, providerId) row exists, and existing rows are never
 * re-toggled or re-priced — only their `meta.import.lastSeenAt` is refreshed.
 * Operator decisions (BSELECTABLE / BACTIVE / BISDEFAULT) therefore survive a
 * re-import, exactly like the catalog seeder.
 */
final readonly class ModelImportApplier
{
    /** Tags the importer may write; anything else in a request is ignored. */
    public const ALLOWED_TAGS = [
        'chat', 'vectorize', 'pic2text', 'rerank', 'sound2text',
        'text2sound', 'text2pic', 'pic2pic', 'text2vid', 'img2vid', 'analyze',
    ];

    private const MAX_PROVIDER_ID = 96;
    private const MAX_NAME = 48;

    public function __construct(
        private EntityManagerInterface $em,
        private ModelRepository $models,
    ) {
    }

    /**
     * @param list<array{providerId: string, name?: string, tags: list<string>}> $rows
     *
     * @return array{created: int, skipped: int, rows: list<array{providerId: string, tag: string, status: string}>}
     */
    public function apply(string $source, array $rows): array
    {
        [$service, $endpointName] = $this->resolveSource($source);
        $now = time();

        $created = 0;
        $skipped = 0;
        $applied = [];

        foreach ($rows as $row) {
            $providerId = trim($row['providerId']);
            if ('' === $providerId || mb_strlen($providerId) > self::MAX_PROVIDER_ID) {
                continue;
            }
            $name = $this->name($row['name'] ?? '', $providerId);

            foreach ($this->normalizeTags($row['tags']) as $tag) {
                $existing = $this->models->findOneBy([
                    'service' => $service,
                    'tag' => $tag,
                    'providerId' => $providerId,
                ]);

                if (null !== $existing) {
                    $this->touchLastSeen($existing, $source, $now);
                    ++$skipped;
                    $applied[] = ['providerId' => $providerId, 'tag' => $tag, 'status' => 'exists'];
                    continue;
                }

                $this->em->persist($this->newModel($service, $endpointName, $providerId, $name, $tag, $source, $now));
                ++$created;
                $applied[] = ['providerId' => $providerId, 'tag' => $tag, 'status' => 'created'];
            }
        }

        $this->em->flush();

        return ['created' => $created, 'skipped' => $skipped, 'rows' => $applied];
    }

    /**
     * @return array{0: string, 1: string|null} [service, endpointName]
     */
    private function resolveSource(string $source): array
    {
        if (ModelDiscoveryService::SOURCE_OLLAMA === $source) {
            return ['ollama', null];
        }
        if (str_starts_with($source, ModelDiscoveryService::OPENAI_COMPATIBLE_PREFIX)) {
            $name = strtolower(trim(substr($source, strlen(ModelDiscoveryService::OPENAI_COMPATIBLE_PREFIX))));

            return [OpenAiCompatibleEndpointRegistry::SERVICE, '' !== $name ? $name : null];
        }

        throw new UnknownImportSourceException('Unknown import source: '.$source);
    }

    private function newModel(string $service, ?string $endpointName, string $providerId, string $name, string $tag, string $source, int $now): Model
    {
        $json = ['meta' => ['import' => ['source' => $source, 'importedAt' => $now, 'lastSeenAt' => $now]]];
        // OpenAICompatibleProvider::resolveForModel() reads BJSON.endpoint to
        // pick the gateway; without it a multi-endpoint install cannot route.
        if (null !== $endpointName) {
            $json['endpoint'] = $endpointName;
        }

        return (new Model())
            ->setService($service)
            ->setTag($tag)
            ->setProviderId($providerId)
            ->setName($name)
            ->setSelectable(1)
            ->setActive(1)
            ->setIsDefault(0)
            ->setJson($json);
    }

    private function touchLastSeen(Model $model, string $source, int $now): void
    {
        $json = $model->getJson();
        $import = $json['meta']['import'] ?? [];
        // Only refresh provenance; never touch toggles, prices or the name.
        $import['lastSeenAt'] = $now;
        $import['source'] ??= $source;
        $import['importedAt'] ??= $now;
        $json['meta']['import'] = $import;
        $model->setJson($json);
    }

    /**
     * @return list<string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        $out = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim((string) $tag));
            if (in_array($tag, self::ALLOWED_TAGS, true) && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return $out;
    }

    private function name(string $name, string $providerId): string
    {
        $name = trim($name);
        if ('' === $name) {
            $name = $providerId;
        }

        return mb_substr($name, 0, self::MAX_NAME);
    }
}
