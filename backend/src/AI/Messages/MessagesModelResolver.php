<?php

declare(strict_types=1);

namespace App\AI\Messages;

use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves a Claude Code / Anthropic model id against BMODELS.
 *
 * Order: exact providerId → exact name → MODEL_ALIASES map → strip trailing
 * `-YYYYMMDD` and retry providerId/name → fail closed (null).
 *
 * Unknown models must NOT pass through: CostCalculationService returns zero
 * cost for a null model_id, which would silently disable budget enforcement.
 *
 * @phpstan-type ResolvedModel array{
 *     provider: string,
 *     providerModelId: string,
 *     displayModel: string,
 *     model_id: int,
 *     requested: string,
 *     aliased_from: string|null
 * }
 */
final readonly class MessagesModelResolver
{
    public function __construct(
        private ModelRepository $modelRepository,
        private MessagesGatewayConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return ResolvedModel|null
     */
    public function resolve(?string $modelString): ?array
    {
        if (null === $modelString || '' === trim($modelString)) {
            return null;
        }

        $requested = trim($modelString);
        $aliasedFrom = null;
        $candidate = $requested;

        $aliases = $this->config->modelAliases();
        if (isset($aliases[$candidate])) {
            $aliasedFrom = $candidate;
            $candidate = $aliases[$candidate];
        } else {
            foreach ($aliases as $pattern => $target) {
                if ($this->matchesAliasPattern($pattern, $candidate)) {
                    $aliasedFrom = $candidate;
                    $candidate = $target;
                    break;
                }
            }
        }

        $model = $this->findActiveModel($candidate);
        if (null === $model) {
            $normalized = $this->stripDatedSuffix($candidate);
            if (null !== $normalized && $normalized !== $candidate) {
                $model = $this->findActiveModel($normalized);
                if (null !== $model) {
                    $candidate = $normalized;
                }
            }
        }

        if (null === $model) {
            $this->logger->info('MessagesGateway: model not resolved', [
                'requested' => $requested,
                'candidate' => $candidate,
                'aliased_from' => $aliasedFrom,
            ]);

            return null;
        }

        return [
            'provider' => strtolower($model->getService()),
            'providerModelId' => $model->getProviderId() ?: $model->getName(),
            'displayModel' => $model->getProviderId() ?: $model->getName(),
            'model_id' => (int) $model->getId(),
            'requested' => $requested,
            'aliased_from' => $aliasedFrom,
        ];
    }

    /**
     * Active Anthropic-service model ids for 404 error messages.
     *
     * @return list<string>
     */
    public function listResolvableAnthropicModelIds(): array
    {
        $models = $this->modelRepository->createQueryBuilder('m')
            ->where('m.active = 1')
            ->andWhere('LOWER(m.service) = :svc')
            ->setParameter('svc', 'anthropic')
            ->orderBy('m.providerId', 'ASC')
            ->getQuery()
            ->getResult();

        $ids = [];
        /** @var list<Model> $models */
        foreach ($models as $model) {
            $id = $model->getProviderId() ?: $model->getName();
            if ('' !== $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function findActiveModel(string $idOrName): ?Model
    {
        $byProviderId = $this->modelRepository->createQueryBuilder('m')
            ->where('m.providerId = :pid')
            ->andWhere('m.active = 1')
            ->setParameter('pid', $idOrName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($byProviderId instanceof Model) {
            return $byProviderId;
        }

        $byName = $this->modelRepository->createQueryBuilder('m')
            ->where('m.name = :name')
            ->andWhere('m.active = 1')
            ->setParameter('name', $idOrName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $byName instanceof Model ? $byName : null;
    }

    private function stripDatedSuffix(string $id): ?string
    {
        if (1 === preg_match('/^(.*)-\d{8}$/', $id, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Supports trailing `*` wildcards in alias keys (e.g. `claude-sonnet-4-*`).
     */
    private function matchesAliasPattern(string $pattern, string $candidate): bool
    {
        if (!str_contains($pattern, '*')) {
            return false;
        }

        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i';

        return 1 === preg_match($regex, $candidate);
    }
}
