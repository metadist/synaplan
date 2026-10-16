<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

/**
 * Outcome of one discovery run (detection only — never writes BMODELS).
 *
 * @phpstan-type PendingModel array{provider: string, id: string, firstSeen: string, daysPending: int, label: string}
 * @phpstan-type FailedProvider array{provider: string, detail: string, failingSince: string, isNew: bool}
 * @phpstan-type BaselineEvent array{provider: string, idCount: int}
 * @phpstan-type ObsoleteIgnore array{key: string, reason: string, decidedOn: string, why: 'gone_upstream'|'now_in_bmodels'}
 * @phpstan-type ProviderStatus array{provider: string, status: string, detail: string|null, listedCount: int, pendingCount: int, silencedByClass: int}
 */
final readonly class ModelDiscoveryReport
{
    /**
     * @param list<ProviderStatus> $providers
     * @param list<PendingModel>   $pending           all pending (console)
     * @param list<PendingModel>   $newPending        not yet announced
     * @param list<PendingModel>   $openPending       announced earlier, still pending
     * @param list<FailedProvider> $failedProviders
     * @param list<BaselineEvent>  $baselinesRecorded
     * @param list<ObsoleteIgnore> $obsoleteIgnores
     * @param array<string, int>   $silencedByClass   provider => count skipped by class rules
     */
    public function __construct(
        public array $providers,
        public array $pending,
        public array $newPending,
        public array $openPending,
        public array $failedProviders,
        public array $baselinesRecorded,
        public array $obsoleteIgnores,
        public array $silencedByClass,
        public bool $shouldNotify,
        public bool $isMondayReminder,
    ) {
    }
}
