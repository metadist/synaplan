<?php

declare(strict_types=1);

namespace App\Module\Observability;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Service\Observability\CapacityService;
use App\Service\Observability\CapacityUnavailableException;

/**
 * Capacity of every server on the system status page — the watchdog's
 * reports (architecture update, AU6 and AU7).
 *
 * Configured while `SYNAPLAN_WATCHDOG` is on, which the image does by
 * default (an unset or empty value counts as on, exactly as the container
 * entrypoint reads it); `0` turns the watchdog off on a server and, on every
 * server, the page off (U11: no nav entry, 404 on the route). The minimal
 * lane sets it to `0`.
 */
final class CapacityModule implements FeatureModuleInterface
{
    public const ID = 'capacity';

    private const OFF = ['0', 'false', 'no', 'off'];

    public function __construct(
        private readonly CapacityService $capacity,
        private readonly string $watchdog,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.capacity.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env('SYNAPLAN_WATCHDOG');
    }

    public function isConfigured(): bool
    {
        return !in_array(strtolower(trim($this->watchdog)), self::OFF, true);
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('SYNAPLAN_WATCHDOG is off');
        }

        try {
            $report = $this->capacity->report();
        } catch (CapacityUnavailableException) {
            return new ModuleStatus(configured: true, healthy: false, message: 'Capacity cannot be read: the shared cache does not answer');
        }

        $reporting = count(array_filter($report['servers'], static fn (array $server): bool => $server['reporting']));
        $known = count($report['servers']);

        return new ModuleStatus(
            configured: true,
            healthy: $reporting > 0,
            message: $reporting > 0
                ? sprintf('%d of %d servers reporting', $reporting, $known)
                : 'No server has reported yet',
            details: ['servers' => $known, 'reporting' => $reporting],
        );
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return ['admin_capacity_*'];
    }

    public function serviceIds(): array
    {
        return [
            CapacityService::class,
            CapacityUnavailableException::class,
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/capacity';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::OtaCandidate;
    }
}
