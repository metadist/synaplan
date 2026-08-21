<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\Entity\Model;
use App\Entity\ModelHealth;
use Psr\Log\LoggerInterface;

/**
 * Switches a model off when the provider clearly retired it, and back on when
 * it returns.
 *
 * The whole design is about provenance. Automation that cannot tell its own
 * changes from an operator's will eventually undo a deliberate decision, and
 * an operator who has had a setting silently reverted stops trusting the
 * feature. So:
 *
 *   - only rows this automation switched off are ever switched back on
 *     ({@see ModelHealth::isAutoDisabled()}),
 *   - a model an operator disabled by hand is never touched,
 *   - an operator re-enabling one of our rows wins, and buys a grace period in
 *     which we report but do not act.
 *
 * Off by default (MODELHEALTH.AUTO_DISABLE_ENABLED). Alerting is safe from day
 * one; retiring a model on a heuristic has to earn its trust first.
 */
final readonly class ModelAutoDisabler
{
    public function __construct(
        private ModelHealthConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Reconcile BMODELS.BACTIVE with the verdict.
     *
     * @return array{disabled: bool, reEnabled: bool}
     */
    public function apply(ModelHealthVerdict $verdict, Model $model, ModelHealth $health, int $now): array
    {
        $isActive = 1 === $model->getActive();

        // An operator switched our row back on. Their call wins, and the grace
        // period stops us from immediately switching it off again — a fight the
        // human would always lose and always notice.
        if ($health->isAutoDisabled() && $isActive) {
            $health->setAutoDisabled(false)
                ->setAutoDisabledAt(0)
                ->setSuppressUntil($now + $this->config->suppressionSeconds());

            $this->logger->info('Model re-enabled by an operator, pausing automatic disabling', [
                'model_id' => $verdict->modelId,
                'model' => $verdict->modelName,
                'until' => $health->getSuppressUntil(),
            ]);

            return ['disabled' => false, 'reEnabled' => false];
        }

        if ($health->isAutoDisabled() && !$isActive && ModelHealthState::Online === $verdict->state) {
            $model->setActive(1);
            $health->setAutoDisabled(false)->setAutoDisabledAt(0);

            $this->logger->info('Model automatically re-enabled: provider offers it again', [
                'model_id' => $verdict->modelId,
                'model' => $verdict->modelName,
            ]);

            return ['disabled' => false, 'reEnabled' => true];
        }

        if (!$this->config->isAutoDisableEnabled()) {
            return ['disabled' => false, 'reEnabled' => false];
        }

        if (!$isActive || $health->isSuppressed($now)) {
            return ['disabled' => false, 'reEnabled' => false];
        }

        // Only a permanent verdict qualifies. A rate limit or a provider
        // hiccup must never be able to retire a model — that is how automation
        // switches off the busiest model during a traffic spike.
        if (ModelHealthState::Offline !== $verdict->state || true !== $verdict->kind?->justifiesAutoDisable()) {
            return ['disabled' => false, 'reEnabled' => false];
        }

        // A verdict built on a catalog listing that only partly covers this
        // capability is worth reporting but not worth acting on.
        if (!$verdict->safeToDisable) {
            return ['disabled' => false, 'reEnabled' => false];
        }

        $model->setActive(0);
        $health->setAutoDisabled(true)->setAutoDisabledAt($now);

        $this->logger->warning('Model automatically disabled: no longer offered by the provider', [
            'model_id' => $verdict->modelId,
            'model' => $verdict->modelName,
            'service' => $verdict->service,
            'reason' => $verdict->message,
        ]);

        return ['disabled' => true, 'reEnabled' => false];
    }
}
