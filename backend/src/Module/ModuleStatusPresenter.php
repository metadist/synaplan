<?php

declare(strict_types=1);

namespace App\Module;

use App\Module\Contract\FeatureModuleInterface;

/**
 * The one JSON shape for "a feature module and its current status", shared by
 * the admin feature-status page and `app:modules:list --json`.
 *
 * Calls `status()` — configured modules may probe their sidecar; absent ones
 * never touch the network.
 */
final readonly class ModuleStatusPresenter
{
    public function __construct(
        private ModuleRegistry $modules,
    ) {
    }

    /**
     * @return list<array<string, mixed>> sorted by module id
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->modules->all() as $module) {
            $rows[] = $this->row($module);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function row(FeatureModuleInterface $module): array
    {
        $status = $module->status();

        return [
            'id' => $module->id(),
            'label_key' => $module->labelKey(),
            'state' => $status->state(),
            'configured' => $status->configured,
            'healthy' => $status->healthy,
            'message' => $status->message,
            'details' => $status->details,
            'configured_by' => $module->configuredBy()->toArray(),
            'capabilities' => $module->capabilityIds(),
            'docs_anchor' => $module->docsAnchor(),
            'mobile_class' => $module->mobileClass()->value,
        ];
    }
}
