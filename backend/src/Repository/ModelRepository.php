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
}
