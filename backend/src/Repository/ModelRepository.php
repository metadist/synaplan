<?php

namespace App\Repository;

use App\Entity\Model;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Model>
 */
class ModelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Model::class);
    }

    /**
     * Get eligible models by tag (capability).
     *
     * @param string     $tag            Model tag (e.g., 'chat', 'pic2text', 'text2pic', 'vectorize')
     * @param bool       $selectableOnly Only selectable models (BSELECTABLE = 1)
     * @param float|null $minRating      Minimum rating filter
     *
     * @return Model[] Array of models sorted by quality DESC, id ASC
     */
    public function findByTag(string $tag, bool $selectableOnly = true, ?float $minRating = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.tag = :tag')
            ->setParameter('tag', $tag)
            ->orderBy('m.quality', 'DESC')
            ->addOrderBy('m.id', 'ASC');

        if ($selectableOnly) {
            $qb->andWhere('m.selectable = 1');
        }

        if (null !== $minRating) {
            $qb->andWhere('m.rating > :minRating')
                ->setParameter('minRating', $minRating);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Every row this install currently offers, regardless of capability.
     *
     * Used by the model-availability check, which must judge what the running
     * database serves rather than what the catalog declares — an install can
     * hold active rows the catalog dropped long ago.
     *
     * @return Model[] Array of active models sorted by service, then id
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.active = 1')
            ->orderBy('m.service', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get model by service and provider ID.
     *
     * @param string $service    Service name (e.g., 'Ollama', 'OpenAI')
     * @param string $providerId Provider-specific model ID
     */
    public function findByServiceAndProviderId(string $service, string $providerId): ?Model
    {
        return $this->createQueryBuilder('m')
            ->where('m.service = :service')
            ->andWhere('m.providerId = :providerId')
            ->setParameter('service', $service)
            ->setParameter('providerId', $providerId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get all available tags/capabilities.
     *
     * @return array Array of tag strings
     */
    public function getAllTags(): array
    {
        $results = $this->createQueryBuilder('m')
            ->select('DISTINCT m.tag')
            ->where('m.selectable = 1')
            ->getQuery()
            ->getScalarResult();

        return array_map(fn ($r) => $r['tag'], $results);
    }

    /**
     * Services behind the chat models a user can actually pick.
     *
     * Deliberately DB-only: this feeds the public runtime config, which must
     * not depend on live provider probes (Ollama's availability check performs
     * an HTTP request, which an unauthenticated endpoint cannot afford).
     *
     * @return string[] Service names as stored, e.g. ['Anthropic', 'OpenAI']
     */
    public function findSelectableChatServices(): array
    {
        $results = $this->createQueryBuilder('m')
            ->select('DISTINCT m.service')
            ->where('m.tag = :tag')
            ->andWhere('m.selectable = 1')
            ->andWhere('m.active = 1')
            ->setParameter('tag', 'chat')
            ->orderBy('m.service', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['service'], $results);
    }

    /**
     * Get all unique provider-capability combinations from DB
     * Returns: ['openai' => ['chat', 'embedding'], 'ollama' => ['chat', 'vectorize'], ...].
     */
    public function getProviderCapabilities(): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('LOWER(m.service) as provider', 'm.tag as capability')
            ->groupBy('m.service', 'm.tag')
            ->orderBy('m.service', 'ASC')
            ->addOrderBy('m.tag', 'ASC');

        $results = $qb->getQuery()->getResult();

        $capabilities = [];
        foreach ($results as $row) {
            $provider = $row['provider'];
            $capability = $row['capability'];

            if (!isset($capabilities[$provider])) {
                $capabilities[$provider] = [];
            }

            if (!in_array($capability, $capabilities[$provider])) {
                $capabilities[$provider][] = $capability;
            }
        }

        return $capabilities;
    }

    /**
     * Find a model with a specific feature (e.g., 'vision', 'reasoning').
     *
     * @param string $feature    Feature name from model's JSON features array
     * @param string $tag        Model tag/capability (default: 'chat')
     * @param bool   $activeOnly Only return active/selectable models
     *
     * @return Model|null First matching model ordered by quality DESC
     */
    public function findByFeature(string $feature, string $tag = 'chat', bool $activeOnly = true): ?Model
    {
        // Get all models of the tag and filter by feature in PHP
        // This is necessary because Doctrine DQL doesn't support JSON_CONTAINS natively
        $models = $this->findByTag($tag, $activeOnly);

        foreach ($models as $model) {
            if ($model->hasFeature($feature)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Find all models with a specific feature.
     *
     * @param string $feature    Feature name from model's JSON features array
     * @param string $tag        Model tag/capability (default: 'chat')
     * @param bool   $activeOnly Only return active/selectable models
     *
     * @return Model[] Array of matching models ordered by quality DESC
     */
    public function findAllByFeature(string $feature, string $tag = 'chat', bool $activeOnly = true): array
    {
        // Get all models of the tag and filter by feature in PHP
        // This is necessary because Doctrine DQL doesn't support JSON_CONTAINS natively
        $models = $this->findByTag($tag, $activeOnly);

        return array_filter($models, fn (Model $model) => $model->hasFeature($feature));
    }

    /**
     * Resolve the catalog row behind a live provider call.
     *
     * Service names are compared case-insensitively: BSERVICE stores them in
     * CamelCase ('OpenAI', 'Groq') while providers report themselves lowercase
     * from getName(). The tag is part of the lookup because one provider model
     * id can back several rows — Groq's Qwen backs both the chat and the vision
     * entry, and a vision outage must not be charged to the chat row.
     */
    public function findIdByServiceProviderIdAndTag(string $service, string $providerId, string $tag): ?int
    {
        $result = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('LOWER(m.service) = :service')
            ->andWhere('LOWER(m.providerId) = :providerId')
            ->andWhere('m.tag = :tag')
            ->setParameter('service', mb_strtolower($service))
            ->setParameter('providerId', mb_strtolower($providerId))
            ->setParameter('tag', $tag)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null === $result ? null : (int) $result['id'];
    }

    /**
     * Every model of one service, keyed by the lowercased provider model id.
     *
     * Feeds the catalog diff: one query per provider instead of one per model.
     *
     * @return array<string, list<Model>>
     */
    public function findByServiceIndexedByProviderId(string $service): array
    {
        $models = $this->createQueryBuilder('m')
            ->where('LOWER(m.service) = :service')
            ->setParameter('service', mb_strtolower($service))
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($models as $model) {
            /* @var Model $model */
            $indexed[mb_strtolower($model->getProviderId())][] = $model;
        }

        return $indexed;
    }

    /**
     * All distinct service names present in the catalog, as stored.
     *
     * @return list<string>
     */
    public function findAllServices(): array
    {
        $results = $this->createQueryBuilder('m')
            ->select('DISTINCT m.service')
            ->orderBy('m.service', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['service'], $results);
    }

    /**
     * Active SOUND2TEXT (or other tag) rows an external STT caller may request.
     *
     * @return list<Model>
     */
    public function findActiveByTag(string $tag): array
    {
        /** @var list<Model> $models */
        $models = $this->createQueryBuilder('m')
            ->where('m.tag = :tag')
            ->andWhere('m.active = 1')
            ->setParameter('tag', $tag)
            ->orderBy('m.quality', 'DESC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $models;
    }

    /**
     * Resolve an active model of a capability by providerId or display name.
     */
    public function findActiveByTagAndIdentity(string $tag, string $identity): ?Model
    {
        /** @var Model|null $model */
        $model = $this->createQueryBuilder('m')
            ->where('m.tag = :tag')
            ->andWhere('m.active = 1')
            ->andWhere('(m.providerId = :identity OR m.name = :identity)')
            ->setParameter('tag', $tag)
            ->setParameter('identity', $identity)
            ->orderBy('m.quality', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $model;
    }
}
