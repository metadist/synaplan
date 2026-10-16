<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Config;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Embedding\Exception\PremiumRequiredException;
use App\Service\Embedding\VectorizeModelChangePolicy;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\Model\Exception\DefaultModelRejectedException;
use App\Service\ModelConfigService;
use App\Service\SelfAware\CapabilityInventory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Default models per capability (DEFAULTMODEL rows), as the AI Models page
 * reads and saves them.
 *
 * Two scopes: a person's own defaults, and the instance defaults (ownerId=0)
 * an admin sets for everyone. What is reported is the model that actually
 * answers — after group allow-lists, locks and the provider-usability
 * fallback — not merely the stored row.
 */
final readonly class ModelDefaultsService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository,
        private ModelConfigService $modelConfigService,
        private IamConfig $iamConfig,
        private VectorizeModelChangePolicy $vectorizePolicy,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
        private ?GroupPolicyService $groupPolicyService = null,
        private ?CapabilityInventory $capabilityInventory = null,
    ) {
    }

    /**
     * The system-wide defaults with the same provider-usability fallback
     * generation applies. Guests run as the ANONYMOUS processing user, which
     * stores no defaults and belongs to no group, so this is exactly what a
     * guest chat uses. With group policies on, a group default still wins for
     * that group's members; this is the layer beneath it.
     *
     * `stored` is the saved row itself, before that fallback, so a caller can
     * put back exactly what was configured.
     *
     * @return array{defaults: array<string, ?int>, locked: array<string, bool>, sources: array<string, ?string>, stored: array<string, ?int>}
     */
    public function instanceDefaults(): array
    {
        $defaults = [];
        $locked = [];
        $sources = [];
        $stored = [];
        foreach (PickerCapabilities::ALL as $capability) {
            $row = $this->storedRow(0, $capability);
            $defaults[$capability] = $this->modelConfigService->getDefaultModel($capability);
            $locked[$capability] = false;
            $sources[$capability] = null !== $row ? 'admin' : null;
            $value = trim((string) $row?->getValue());
            $stored[$capability] = is_numeric($value) ? (int) $value : null;
        }

        return [
            'defaults' => $defaults,
            'locked' => $locked,
            'sources' => $sources,
            'stored' => $stored,
        ];
    }

    /**
     * The defaults the AI Models page shows for a person, per capability.
     *
     * @return array{defaults: array<string, ?int>, locked: array<string, bool>, sources: array<string, ?string>}
     */
    public function userDefaults(int $userId): array
    {
        $defaults = [];
        $locked = [];
        $sources = [];
        $groupPolicies = null !== $this->layeredConfigResolver
            && $this->iamConfig->isGroupPoliciesEnabled($userId);

        foreach (PickerCapabilities::ALL as $capability) {
            // VECTORIZE is system-wide (one Qdrant collection, one
            // dimension), so the per-user lookup is skipped and the dropdown
            // can never disagree with what the indexer uses. Group policies
            // are the exception: the indexer then resolves through the
            // allow-list, so the dropdown follows that same model.
            if ('VECTORIZE' === $capability && !$groupPolicies) {
                $config = $this->storedRow(0, 'VECTORIZE');
                $sources[$capability] = null !== $config ? 'admin' : null;
                if ($config) {
                    $modelId = (int) $config->getValue();
                    $model = $this->modelRepository->find($modelId);
                    $defaults[$capability] = ($model && 1 === $model->getActive()) ? $modelId : null;
                } else {
                    $defaults[$capability] = null;
                }
                $locked[$capability] = null !== $this->layeredConfigResolver
                    && $this->layeredConfigResolver->isLocked('DEFAULTMODEL', $capability, $userId);
                continue;
            }

            // The model generation will use: allow-list and locks when group
            // policies are on, and the provider-usability fallback either way.
            // An active row whose provider has no key is not what runs.
            $reported = $this->modelConfigService->reportedDefault($capability, $userId);
            $defaults[$capability] = $reported['id'];
            $locked[$capability] = $reported['locked'];
            $sources[$capability] = $reported['source'];
        }

        return [
            'defaults' => $defaults,
            'locked' => $locked,
            'sources' => $sources,
        ];
    }

    /**
     * Save per-capability defaults for the person, or for everyone when
     * `$global` is set (the caller has checked the admin role).
     *
     * Every check runs before the first write, so a refused save changes
     * nothing. Unknown capabilities are ignored; a model that is gone or
     * inactive is skipped and reported.
     *
     * @param array<mixed, mixed> $requested capability => model id
     *
     * @return array{message: string, skipped?: array<string, mixed>, defaults?: array<string, ?int>, replaced?: array<string, ?int>}
     *
     * @throws PremiumRequiredException      switching the embedding model needs a paid plan
     * @throws DefaultModelRejectedException a group lock or allow-list forbids the model
     */
    public function save(User $user, array $requested, bool $global): array
    {
        $userId = (int) $user->getId();
        $ownerId = $global ? 0 : $userId;

        $vectorizeChanged = $this->vectorizePolicy->isChange($requested);
        if ($vectorizeChanged) {
            $this->vectorizePolicy->assertCanChange($user);
        }

        if (!$global) {
            $this->assertGroupPolicyAllows($userId, $requested, $vectorizeChanged);
        }

        $skipped = $this->write($ownerId, $requested, $vectorizeChanged);

        if ($vectorizeChanged) {
            $this->vectorizePolicy->changed();
        }
        $this->capabilityInventory?->forget($global ? null : $user->getId());

        $result = [
            'message' => $global ? 'Global default models saved successfully' : 'Default models saved successfully',
        ];

        if (!empty($skipped)) {
            $result['skipped'] = $skipped;
            $result['message'] .= ' (some models were skipped because they are no longer available)';
        }

        if (!$global) {
            // The lock and allow-list checks above memoized the pre-save chain.
            $this->layeredConfigResolver?->reset();
            $effective = $this->userDefaults($userId)['defaults'];
            $result['defaults'] = $effective;

            $replaced = $this->replacedDefaults($requested, $effective, $skipped);
            if ([] !== $replaced) {
                $result['replaced'] = $replaced;
            }
        }

        return $result;
    }

    /**
     * Replace the person's overrides with the recommended defaults that are
     * usable on this installation. Other people and the instance defaults
     * are not touched.
     *
     * @return array{message: string, defaults: array<string, int>}
     */
    public function resetToRecommended(int $userId): array
    {
        $result = $this->modelConfigService->resetUserDefaults($userId);

        return [
            'message' => sprintf(
                'Applied %d recommended default(s) (removed %d previous override(s))',
                $result['written'],
                $result['removed'],
            ),
            'defaults' => $result['defaults'],
        ];
    }

    /**
     * @param array<mixed, mixed> $requested
     *
     * @throws DefaultModelRejectedException
     */
    private function assertGroupPolicyAllows(int $userId, array $requested, bool $vectorizeChanged): void
    {
        if (null === $this->layeredConfigResolver || !$this->iamConfig->isGroupPoliciesEnabled($userId)) {
            return;
        }

        foreach ($requested as $capability => $modelId) {
            if (!is_string($capability)) {
                continue;
            }
            if ('VECTORIZE' === $capability && !$vectorizeChanged) {
                continue;
            }
            if ($this->layeredConfigResolver->isLocked('DEFAULTMODEL', $capability, $userId)) {
                throw DefaultModelRejectedException::locked($capability);
            }
            if (is_numeric($modelId)
                && null !== $this->groupPolicyService
                && !$this->groupPolicyService->isModelAllowed($userId, (int) $modelId)
            ) {
                throw DefaultModelRejectedException::notAllowed($capability);
            }
        }
    }

    /**
     * @param array<mixed, mixed> $requested
     *
     * @return array<string, mixed> capability => requested id, for models that are gone or inactive
     */
    private function write(int $ownerId, array $requested, bool $vectorizeChanged): array
    {
        $skipped = [];

        foreach ($requested as $capability => $modelId) {
            if (!is_string($capability) || !in_array($capability, PickerCapabilities::ALL, true)) {
                continue;
            }

            // An unchanged VECTORIZE is not written: no one reads a per-user
            // row for it, and rewriting it would refresh the embedding cache
            // for nothing (#891).
            if ('VECTORIZE' === $capability && !$vectorizeChanged) {
                continue;
            }

            $model = $this->modelRepository->find($modelId);
            if (!$model || 1 !== $model->getActive()) {
                $skipped[$capability] = $modelId;
                continue;
            }

            // Written to $ownerId only. A user-scoped VECTORIZE must never
            // escalate into a global change (PR #853); only `global`, which
            // requires the admin role, writes ownerId=0.
            $config = $this->storedRow($ownerId, $capability);
            if (!$config) {
                $config = new Config();
                $config->setOwnerId($ownerId);
                $config->setGroup('DEFAULTMODEL');
                $config->setSetting($capability);
            }

            $config->setValue((string) $modelId);
            $this->em->persist($config);
        }

        $this->em->flush();

        return $skipped;
    }

    /**
     * Requested capabilities whose saved model is not the one that applies,
     * mapped to the model that applies instead (null when none does).
     *
     * @param array<mixed, mixed>  $requested
     * @param array<string, ?int>  $effective
     * @param array<string, mixed> $skipped
     *
     * @return array<string, ?int>
     */
    private function replacedDefaults(array $requested, array $effective, array $skipped): array
    {
        $replaced = [];
        foreach ($requested as $capability => $modelId) {
            // VECTORIZE always resolves through the global row, so a per-user
            // echo of it is never the applied value and is not a replacement.
            if (!is_string($capability) || 'VECTORIZE' === $capability || !is_numeric($modelId)
                || isset($skipped[$capability]) || !array_key_exists($capability, $effective)
            ) {
                continue;
            }
            $requestedId = (int) $modelId;
            if ($effective[$capability] !== $requestedId) {
                $replaced[$capability] = $effective[$capability];
                continue;
            }
            // getDefaultModel() hands back the saved id when nothing else can
            // serve. That is still a replacement: no model applies.
            if (!$this->modelConfigService->isConfiguredModelUsable($requestedId)) {
                $replaced[$capability] = null;
            }
        }

        return $replaced;
    }

    private function storedRow(int $ownerId, string $capability): ?Config
    {
        return $this->configRepository->findOneBy([
            'ownerId' => $ownerId,
            'group' => 'DEFAULTMODEL',
            'setting' => $capability,
        ]);
    }
}
