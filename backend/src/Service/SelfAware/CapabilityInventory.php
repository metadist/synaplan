<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

/**
 * Live per-install / per-user capability snapshot used by the self-aware chat.
 */
interface CapabilityInventory
{
    public function build(int $userId): CapabilityReport;

    /**
     * Drop cached reports. Pass a user id to invalidate that user only;
     * omit it (or pass null) to bump the generation so every cached report
     * expires (provider-key / default-model changes).
     */
    public function forget(?int $userId = null): void;
}
