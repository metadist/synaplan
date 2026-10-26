<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Starts and leaves the Synaplan Demo on this install (plan §5.4).
 *
 * Activation asks the server for a scoped key, registers it as the
 * `synaplan-demo` OpenAI-compatible endpoint, imports the aliases and moves
 * the instance-wide purposes onto them after recording what they were.
 * Anything that fails after the key arrived is undone locally; the key itself
 * is kept by the server for this install id, so a retry gets it back without
 * minting a second one.
 *
 * Leaving undoes all of it locally first and then revokes the key. When the
 * server cannot be reached the revoke is remembered and delivered by
 * {@see maintain()} from the scheduler.
 */
final readonly class FreeApiActivator
{
    private const LOCK_NAME = 'freeapi-activation';
    private const LOCK_TTL_SECONDS = 120.0;

    /** Refusals after which the key is gone on the server anyway; nothing left to revoke. */
    private const KEY_ALREADY_GONE = [FreeApiException::INVALID_KEY, 'freeapi_expired', 'freeapi_blocked'];

    public function __construct(
        private FreeApiConfig $config,
        private FreeApiState $state,
        private FreeApiClient $client,
        private FreeApiInstallFacts $facts,
        private OpenAiCompatibleEndpointRegistry $endpoints,
        private FreeApiModelBinder $binder,
        private ChatReadinessService $chatReadiness,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool     $force       also when chat already works with an own provider (the Demo then takes over the defaults)
     * @param int|null $activatedBy the admin who started it; null from the console
     *
     * @throws FreeApiException when the module is off or the server refused
     */
    public function activate(string $locale = 'en', ?string $contactEmail = null, bool $force = false, ?int $activatedBy = null): FreeApiActivation
    {
        $baseUrl = $this->config->baseUrl();
        if (!$this->config->isConfigured() || null === $baseUrl) {
            throw FreeApiException::notConfigured();
        }

        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            if ($this->state->isActive()) {
                throw new FreeApiException('The Synaplan Demo is already on. Turn it off first to start it again.', FreeApiException::ALREADY_ACTIVE);
            }
            if ($this->hasOwnEndpoint()) {
                throw new FreeApiException('This server already uses its own OpenAI-compatible endpoint, so the Synaplan Demo is not needed.', FreeApiException::OWN_ENDPOINT);
            }
            if (!$force && $this->chatReadiness->isChatReady()) {
                throw new FreeApiException('Chat already works on this server with your own AI provider, so the Synaplan Demo is not needed.', FreeApiException::CHAT_READY);
            }

            $installId = $this->state->installId();
            $issued = FreeApiIssuedKey::fromResponse($this->issue($installId, $locale, $contactEmail));
            if ($issued->protocolMin > FreeApiConfig::PROTOCOL) {
                throw new FreeApiException('This Synaplan version is too old for the Demo. Update Synaplan, or add your own AI provider.', 'freeapi_upgrade_required');
            }

            return $this->install($issued, $baseUrl, $installId, $activatedBy);
        } finally {
            $lock->release();
        }
    }

    /**
     * Safe to call at any time; does nothing when the Demo is not active.
     */
    public function leave(): FreeApiLeave
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            $wasActive = $this->state->isActive();
            $expiresAt = $this->state->expiresAt();
            $key = $this->endpoints->getEndpoint(FreeApiConfig::ENDPOINT_NAME)['api_key'] ?? '';

            $restored = $this->binder->restore($this->state->snapshot() ?? []);
            $this->binder->deactivate();
            $this->endpoints->deleteEndpoint(FreeApiConfig::ENDPOINT_NAME);
            $this->state->clearSnapshot();
            $this->state->markInactive();
            $this->chatReadiness->invalidate();

            $revoked = '' === $key || $this->revoke($key, $expiresAt);
            if ($wasActive) {
                $this->logger->info('Synaplan Demo left', ['restored' => $restored, 'key_revoked' => $revoked]);
            }

            return new FreeApiLeave($wasActive, $restored, $revoked);
        } finally {
            $lock->release();
        }
    }

    /**
     * Scheduler backstop. Leaves the Demo once its key has expired or the
     * module was switched off, so chat never stays bound to a Demo that no
     * longer answers, and delivers a revoke that could not reach the server.
     */
    public function maintain(): FreeApiMaintenance
    {
        $leftBecause = null;
        if ($this->state->isActive()) {
            $expiresAt = $this->state->expiresAt();
            if (!$this->config->isConfigured()) {
                $leftBecause = FreeApiMaintenance::LEFT_DISABLED;
            } elseif (null !== $expiresAt && $expiresAt <= time()) {
                $leftBecause = FreeApiMaintenance::LEFT_EXPIRED;
            }
            if (null !== $leftBecause) {
                $this->leave();
                $this->logger->info('Synaplan Demo left by the scheduler', ['reason' => $leftBecause]);
            } else {
                $this->binder->realignPurposeRows();
            }
        }

        return new FreeApiMaintenance($leftBecause, !$this->deliverPendingRevoke());
    }

    /**
     * @return bool true when nothing is pending any more
     */
    private function deliverPendingRevoke(): bool
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            $pending = $this->state->pendingRevoke();
            if (null === $pending) {
                return true;
            }
            $until = $this->state->pendingRevokeUntil();
            $expired = null !== $until && $until <= time();
            // The server hands an install its unrevoked key again; never revoke the key chat runs on.
            $inUse = $this->state->isActive()
                && $pending === ($this->endpoints->getEndpoint(FreeApiConfig::ENDPOINT_NAME)['api_key'] ?? null);
            if ($expired || $inUse || $this->revoke($pending, $until)) {
                $this->state->clearPendingRevoke();

                return true;
            }

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(string $installId, string $locale, ?string $contactEmail): array
    {
        $payload = [
            'install_id' => $installId,
            'platform' => $this->config->platform(),
            'version' => $this->config->appVersion(),
            'protocol' => FreeApiConfig::PROTOCOL,
            'terms_version' => FreeApiConfig::TERMS_VERSION,
            'locale' => 1 === preg_match('/^[A-Za-z-]{2,16}$/', $locale) ? $locale : 'en',
            'host_kind' => $this->facts->hostKind(),
            'host_hash' => $this->facts->hostHash(),
        ];
        if (null !== $contactEmail && '' !== trim($contactEmail)) {
            $payload['contact_email'] = trim($contactEmail);
        }

        // The server may raise the work it asks for; one retry at the client's ceiling covers that.
        foreach ([FreeApiProofOfWork::DEFAULT_BITS, FreeApiProofOfWork::MAX_BITS] as $attempt => $bits) {
            $nonce = FreeApiProofOfWork::solve($installId, $bits);
            if (null === $nonce) {
                throw new FreeApiException('The Synaplan Demo could not be started on this server right now. Try again in a minute.', FreeApiException::POW_REQUIRED);
            }
            try {
                return $this->client->issueKey($payload + ['pow_nonce' => $nonce]);
            } catch (FreeApiException $e) {
                if (FreeApiException::POW_REQUIRED !== $e->errorCode || $attempt > 0) {
                    throw $e;
                }
            }
        }

        throw new FreeApiException('The Synaplan Demo could not be started on this server right now. Try again in a minute.', FreeApiException::POW_REQUIRED);
    }

    private function install(FreeApiIssuedKey $issued, string $baseUrl, string $installId, ?int $activatedBy): FreeApiActivation
    {
        $snapshotTaken = null === $this->state->snapshot();
        try {
            $this->endpoints->saveEndpoint(
                FreeApiConfig::ENDPOINT_NAME,
                $baseUrl.'/v1',
                $issued->key,
                [
                    'X-Synaplan-Install' => $installId,
                    'X-Synaplan-Version' => $this->config->appVersion(),
                    'X-Synaplan-Protocol' => (string) FreeApiConfig::PROTOCOL,
                ],
                FreeApiConfig::ENDPOINT_LABEL,
                FreeApiAliases::ENDPOINT_CAPABILITIES,
            );
            if ($snapshotTaken) {
                $this->state->saveSnapshot($this->binder->snapshot());
            }
            $modelIds = $this->binder->importAliases($issued->aliases);
            if (!isset($modelIds['free-chat'][FreeApiAliases::TAGS['free-chat']])) {
                throw FreeApiException::invalidResponse('no chat alias');
            }
            $bound = $this->binder->bind($modelIds);
            $this->state->markActive($issued->keyId, $issued->expiresAt, $issued->limits, time(), $activatedBy);
            if ($issued->key === $this->state->pendingRevoke()) {
                $this->state->clearPendingRevoke();
            }
        } catch (\Throwable $e) {
            // A snapshot left by a crash before markActive() must be restored too.
            // Otherwise this retry hides the Demo rows the defaults still point at.
            $clean = $this->rollBack($snapshotTaken || !$this->state->isActive());
            if ($e instanceof FreeApiException && $clean) {
                throw $e;
            }
            if (!$clean) {
                throw new FreeApiException('The Synaplan Demo could not be set up, and some AI settings may still point at it. Run app:freeapi:leave to restore them.', FreeApiException::SETUP_INCOMPLETE, 0, null, $e);
            }
            throw new FreeApiException('The Synaplan Demo could not be set up on this server. Nothing was changed.', FreeApiException::INVALID_RESPONSE, 0, null, $e);
        }
        $this->chatReadiness->invalidate();
        $this->logger->info('Synaplan Demo activated', ['bound' => $bound, 'expires_at' => $issued->expiresAt]);

        return new FreeApiActivation(
            $issued->expiresAt,
            $issued->daysLeft,
            $issued->limits,
            $bound,
            !\in_array(FreeApiAliases::VECTORIZE, $bound, true),
        );
    }

    /**
     * @param bool $restoreSnapshot put the stored pre-Demo bindings back, then drop the snapshot
     *
     * @return bool false when the rollback itself failed and settings may still point at the Demo
     */
    private function rollBack(bool $restoreSnapshot): bool
    {
        try {
            if ($restoreSnapshot) {
                $this->binder->restore($this->state->snapshot() ?? []);
                $this->state->clearSnapshot();
            }
            if (!$this->state->isActive()) {
                $this->binder->deactivate();
                $this->endpoints->deleteEndpoint(FreeApiConfig::ENDPOINT_NAME);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Synaplan Demo rollback failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Older imports carry no BJSON.endpoint and route to "the only endpoint";
     * a second one would leave them without a gateway.
     */
    private function hasOwnEndpoint(): bool
    {
        foreach ($this->endpoints->listEndpoints() as $endpoint) {
            if (FreeApiConfig::ENDPOINT_NAME !== $endpoint['name']) {
                return true;
            }
        }

        return false;
    }

    private function revoke(string $key, ?int $expiresAt): bool
    {
        try {
            $this->client->revokeKey($key);

            return true;
        } catch (FreeApiException $e) {
            if (\in_array($e->errorCode, self::KEY_ALREADY_GONE, true)) {
                return true;
            }
            $this->state->rememberPendingRevoke($key, $expiresAt);
            $this->logger->warning('Synaplan Demo key revoke deferred', ['code' => $e->errorCode]);

            return false;
        }
    }
}
