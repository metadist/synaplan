<?php

declare(strict_types=1);

namespace App\Service\Iap;

use App\Service\Iap\Exception\IapNotConfiguredException;
use App\Service\Iap\Exception\IapVerificationException;
use AppStoreServerLibrary\Models\Environment;
use AppStoreServerLibrary\Models\JWSTransactionDecodedPayload;
use AppStoreServerLibrary\Models\Status;
use AppStoreServerLibrary\SignedDataVerifier;
use AppStoreServerLibrary\SignedDataVerifier\VerificationException;

/**
 * MOBILE-APP SEAM (Epic 5.4): real Apple verifier.
 *
 * Thin adapter over the App Store Server Library's {@see SignedDataVerifier},
 * which performs full x5c certificate-chain verification of StoreKit 2 JWS data
 * against Apple's root CAs. All store-specific shapes are normalized to
 * {@see IapEntitlement} here so the rest of the app stays store-agnostic.
 *
 * NOTE: the verification path is intentionally NOT unit tested — it needs real
 * Apple root certificates + bundle id and produces values only Apple can sign.
 * The business logic that consumes it ({@see \App\Service\MobilePurchaseService})
 * is tested against {@see AppleReceiptVerifierInterface} fakes instead. The
 * configuration guards below run before any Apple data is touched and ARE
 * covered, because a misconfigured deployment fails silently in production.
 */
final class AppleStoreKitVerifier implements AppleReceiptVerifierInterface
{
    private ?SignedDataVerifier $verifier = null;

    private readonly ?int $appAppleId;

    private readonly string $rootCertsDir;

    public function __construct(
        private readonly string $bundleId = '',
        int $appAppleId = 0,
        private readonly string $environment = 'production',
        string $rootCertsDir = '',
        private readonly bool $enableOnlineChecks = false,
        string $projectDir = '',
    ) {
        // Env wiring passes 0 when APPLE_APP_APPLE_ID is unset; normalize to null
        // (the verifier only requires it for the Production environment).
        $this->appAppleId = $appAppleId > 0 ? $appAppleId : null;

        // A relative IAP_APPLE_ROOT_CERTS_DIR (e.g. "var/apple-roots") must be
        // anchored to the project dir — the web SAPI's cwd is public/, so a
        // bare is_dir() check would silently report "not configured" there.
        if ('' !== $rootCertsDir && '' !== $projectDir && !str_starts_with($rootCertsDir, '/')) {
            $rootCertsDir = rtrim($projectDir, '/').'/'.$rootCertsDir;
        }
        $this->rootCertsDir = $rootCertsDir;
    }

    public function isConfigured(): bool
    {
        return '' !== $this->bundleId && [] !== $this->rootCertificates();
    }

    public function verifySignedTransaction(string $signedTransaction): IapEntitlement
    {
        $verifier = $this->verifier();

        try {
            $tx = $verifier->verifyAndDecodeSignedTransaction($signedTransaction);
        } catch (VerificationException $e) {
            throw new IapVerificationException('Apple transaction verification failed: '.$e->getMessage(), 0, $e);
        }

        return $this->toEntitlement($tx, $this->stateFromTransaction($tx));
    }

    public function verifyNotification(string $signedPayload): IapEntitlement
    {
        $verifier = $this->verifier();

        try {
            $notification = $verifier->verifyAndDecodeNotification($signedPayload);
            $data = $notification->getData();
            $signedTx = $data?->getSignedTransactionInfo();
            if (null === $signedTx) {
                throw new IapVerificationException('Apple notification carries no transaction info.');
            }
            $tx = $verifier->verifyAndDecodeSignedTransaction($signedTx);
        } catch (VerificationException $e) {
            throw new IapVerificationException('Apple notification verification failed: '.$e->getMessage(), 0, $e);
        }

        // Prefer the subscription status from the notification payload (carries
        // grace/retry/revoked); fall back to deriving from the transaction.
        $state = match ($data->getStatus()) {
            Status::ACTIVE => IapEntitlementState::ACTIVE,
            Status::BILLING_GRACE_PERIOD => IapEntitlementState::GRACE_PERIOD,
            Status::BILLING_RETRY => IapEntitlementState::ON_HOLD,
            Status::EXPIRED => IapEntitlementState::EXPIRED,
            Status::REVOKED => IapEntitlementState::REVOKED,
            default => $this->stateFromTransaction($tx),
        };

        return $this->toEntitlement($tx, $state);
    }

    private function toEntitlement(JWSTransactionDecodedPayload $tx, IapEntitlementState $state): IapEntitlement
    {
        $purchaseId = $tx->getOriginalTransactionId() ?? $tx->getTransactionId();
        if (null === $purchaseId) {
            throw new IapVerificationException('Apple transaction has no identifier.');
        }

        $expiresMs = $tx->getExpiresDate();

        return new IapEntitlement(
            platform: IapPlatform::APPLE,
            productId: $tx->getProductId() ?? '',
            purchaseId: $purchaseId,
            state: $state,
            expiresAt: null !== $expiresMs ? intdiv($expiresMs, 1000) : null,
            autoRenew: true,
            environment: Environment::SANDBOX === $tx->getEnvironment() ? 'sandbox' : 'production',
            accountBinding: $tx->getAppAccountToken(),
        );
    }

    /**
     * A standalone transaction has no grace/retry signal, so derive a coarse
     * state: revoked → REVOKED, past expiry → EXPIRED, otherwise ACTIVE.
     */
    private function stateFromTransaction(JWSTransactionDecodedPayload $tx): IapEntitlementState
    {
        if (null !== $tx->getRevocationDate()) {
            return IapEntitlementState::REVOKED;
        }

        $expiresMs = $tx->getExpiresDate();
        if (null !== $expiresMs && intdiv($expiresMs, 1000) <= time()) {
            return IapEntitlementState::EXPIRED;
        }

        return IapEntitlementState::ACTIVE;
    }

    private function verifier(): SignedDataVerifier
    {
        if (!$this->isConfigured()) {
            throw new IapNotConfiguredException($this->missingConfigurationMessage());
        }

        if (null === $this->verifier) {
            $environment = $this->environment();
            try {
                $this->verifier = new SignedDataVerifier(
                    rootCertificates: $this->rootCertificates(),
                    enableOnlineChecks: $this->enableOnlineChecks,
                    environment: $environment,
                    bundleId: $this->bundleId,
                    appAppleId: $this->appAppleId,
                );
            } catch (VerificationException|\ValueError $e) {
                throw new IapNotConfiguredException('Apple verifier could not be initialized: '.$e->getMessage(), 0, $e);
            }
        }

        return $this->verifier;
    }

    /**
     * Names what is actually missing. The generic "not configured" message cost
     * hours once: a container recreate wiped the root-cert directory, every
     * purchase failed with an opaque client-side error, and the server log did
     * not say which half of the configuration was gone.
     */
    private function missingConfigurationMessage(): string
    {
        $missing = [];
        if ('' === $this->bundleId) {
            $missing[] = 'IAP_APPLE_BUNDLE_ID is empty';
        }
        if ('' === $this->rootCertsDir) {
            $missing[] = 'IAP_APPLE_ROOT_CERTS_DIR is empty';
        } elseif ([] === $this->rootCertificates()) {
            $missing[] = sprintf(
                'no Apple root certificates in "%s" (download them from https://www.apple.com/certificateauthority/ and keep them in DER form; a bind mount survives container recreation, a copy into the container does not)',
                $this->rootCertsDir
            );
        }

        return 'Apple IAP is not configured on this server: '.implode('; ', $missing).'.';
    }

    /**
     * Apple spells the environment `Sandbox`/`Production`. Accept any casing but
     * refuse an outright unknown value instead of silently falling back to
     * Production — that fallback turns a typo into every sandbox receipt being
     * rejected, with nothing in the log pointing at the environment.
     */
    private function environment(): Environment
    {
        if ('' === $this->environment) {
            return Environment::PRODUCTION;
        }

        foreach (Environment::cases() as $case) {
            if (0 === strcasecmp($case->value, $this->environment)) {
                return $case;
            }
        }

        throw new IapNotConfiguredException(sprintf('IAP_APPLE_ENVIRONMENT is "%s"; expected one of %s.', $this->environment, implode(', ', array_map(static fn (Environment $c): string => $c->value, Environment::cases()))));
    }

    /**
     * Apple root CA certificate contents from the configured directory.
     *
     * @return string[]
     */
    private function rootCertificates(): array
    {
        if ('' === $this->rootCertsDir || !is_dir($this->rootCertsDir)) {
            return [];
        }

        $certs = [];
        foreach (glob(rtrim($this->rootCertsDir, '/').'/*') ?: [] as $path) {
            if (is_file($path)) {
                $contents = file_get_contents($path);
                if (false !== $contents && '' !== $contents) {
                    $certs[] = $contents;
                }
            }
        }

        return $certs;
    }
}
