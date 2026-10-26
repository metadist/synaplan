<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * The one name a server has, shared with the capacity watchdog.
 *
 * `SYNAPLAN_SERVER_ID` when the operator set it (the platform exports `web1`
 * … `web3` per node), else the hostname, which on Kubernetes is the pod name
 * and on compose the container id. The watchdog applies the same rule for the
 * key `capacity:server:{id}`, so the card on the capacity page, the
 * `X-Synaplan-Server` response header and a support ticket all name one thing
 * (architecture update, contracts §6.1).
 */
final readonly class ServerIdentity
{
    public const HEADER = 'X-Synaplan-Server';

    private const FALLBACK = 'synaplan';

    public function __construct(
        private ?string $configuredId,
    ) {
    }

    public function id(): string
    {
        $configured = self::sanitize($this->configuredId ?? '');
        if ('' !== $configured) {
            return $configured;
        }

        $hostname = gethostname();
        $hostname = false === $hostname ? '' : self::sanitize($hostname);

        return '' !== $hostname ? $hostname : self::FALLBACK;
    }

    /**
     * The container hostname PHP stamps on event-ring records, unchanged.
     */
    public function hostname(): string
    {
        $hostname = gethostname();

        return false === $hostname || '' === $hostname ? self::FALLBACK : $hostname;
    }

    /**
     * Same character set as the watchdog: a server id is a label, never a path
     * or a header injection.
     */
    private static function sanitize(string $raw): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '', $raw);
    }
}
