<?php

declare(strict_types=1);

namespace App\Service\Config\Readiness;

/**
 * One row of the `services` list on `GET /api/v1/config/features`. Name and
 * copy come from {@see ServiceReadinessCatalog}.
 */
final readonly class ServiceReadiness
{
    public const STARTING = 'starting';
    public const DOWNLOADING = 'downloading';
    public const READY = 'ready';
    public const OFF = 'off';
    public const UNAVAILABLE = 'unavailable';
    public const FAILED = 'failed';

    public function __construct(
        public string $id,
        public string $state,
        public ?string $recovery,
        public ?string $since,
        public ?DownloadProgress $download = null,
    ) {
    }

    /**
     * @return array{id: string, name: string, state: string, unlocks: string, effect: ?string, recovery: ?string, since: ?string, percent: ?int, model: ?string, minutes_left: ?int, profile: ?string, docs_anchor: ?string}
     */
    public function toArray(): array
    {
        $service = ServiceReadinessCatalog::SERVICES[$this->id];

        return [
            'id' => $this->id,
            'name' => $service['name'],
            'state' => $this->state,
            'unlocks' => $service['unlocks'],
            'effect' => self::READY === $this->state ? null : $service['effect'],
            'recovery' => $this->recovery,
            'since' => $this->since,
            'percent' => $this->download?->percent,
            'model' => $this->download?->model,
            'minutes_left' => $this->download?->minutesLeft,
            'profile' => self::OFF === $this->state ? $service['profile'] : null,
            'docs_anchor' => $service['docs'],
        ];
    }
}
