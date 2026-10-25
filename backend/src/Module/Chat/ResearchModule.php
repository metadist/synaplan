<?php

declare(strict_types=1);

namespace App\Module\Chat;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Service\Research\Run\ResearchCapabilities;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchReaper;
use App\Service\Research\Run\ResearchReportDelivery;
use App\Service\Research\Run\ResearchRunService;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchStoreUnavailable;

/**
 * Long research runs. Off means a new run cannot start. A run that already
 * exists can still be read, stopped and deleted.
 */
final class ResearchModule implements FeatureModuleInterface
{
    public const ID = 'research';

    public function __construct(
        private readonly ResearchConfig $config,
        private readonly ResearchStateStore $store,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.research.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(
            envKeys: ['RESEARCH_ENABLED'],
            bconfigKeys: [ResearchConfig::GROUP.'.'.ResearchConfig::ENABLED],
        );
    }

    public function isConfigured(): bool
    {
        return $this->config->isEnabled();
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('Research is turned off');
        }
        try {
            $runs = $this->store->activeRuns();
        } catch (ResearchStoreUnavailable) {
            return new ModuleStatus(
                configured: true,
                healthy: false,
                message: 'Research is on, but its store did not answer',
            );
        }
        $active = count($runs);
        foreach ($runs as $run) {
            if ($this->workerQuiet($run)) {
                return new ModuleStatus(
                    configured: true,
                    healthy: false,
                    message: 'No worker has picked up research units for 5 minutes.',
                    details: ['active' => $active],
                );
            }
        }

        return new ModuleStatus(
            configured: true,
            healthy: true,
            message: $active.' research run(s) are in progress',
            details: ['active' => $active],
        );
    }

    private function workerQuiet(ResearchRunState $run): bool
    {
        if ($run->status->isTerminal()
            || ResearchStatus::WaitingAnswer === $run->status
            || ResearchStatus::WaitingPlan === $run->status
            || $this->store->isClaimed($run->ref->id, $run->expectedSeq)) {
            return false;
        }
        $updated = $run->updatedAt ?? $run->ref->createdAt;

        return (new \DateTimeImmutable())->getTimestamp() - $updated->getTimestamp() >= ResearchReaper::QUIET_SECONDS;
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return [
            'api_research_capabilities',
            'api_research_start',
            'api_research_input',
            'api_research_followups',
            'v1_research_capabilities',
            'v1_research_start',
            'v1_research_input',
            'v1_research_followups',
        ];
    }

    public function serviceIds(): array
    {
        return [
            ResearchConfig::class,
            ResearchRunService::class,
            ResearchReportDelivery::class,
            ResearchCapabilities::class,
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/research';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}
