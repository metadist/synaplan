<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\ExternalIdentityRepository;
use App\Repository\OAuthTokenRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformSsoException;
use App\Service\PlatformLink\LifecycleEffect;
use App\Service\PlatformLink\PlatformLifecycleService;

/**
 * The link between a person and a cloud account, kept in step with the
 * person's OAuth2 grant: consent creates it, and a cloud revoking the
 * person's refresh token (the cloud account was deleted or disabled, or the
 * person disconnected there) ends it.
 */
final readonly class CloudLinkService
{
    public function __construct(
        private ExternalIdentityRepository $identities,
        private OAuthTokenRepository $tokens,
        private LifecycleEffect $effects,
        private AuditLogWriter $audit,
        private PlatformLifecycleService $lifecycle,
    ) {
    }

    /**
     * The person consented: the cloud account $externalId is theirs. When it
     * was another person's before, that person's grant ends.
     */
    public function link(int $userId, PlatformInstance $instance, string $externalId, string $ip): ExternalIdentity
    {
        $existing = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId);
        $previousOwner = $existing instanceof ExternalIdentity ? $existing->getUserId() : null;
        if (null !== $previousOwner && $previousOwner !== $userId) {
            $this->tokens->revokeAll($instance->getInstanceId(), $previousOwner);
        }
        $identity = $this->identities->upsert($userId, $instance->getClient(), $externalId, $instance->getInstanceId(), null, ExternalIdentity::MODE_LINK);
        $identity->setStatus(ExternalIdentity::STATUS_ACTIVE);
        $identity->setRevokedAt(null);
        $this->identities->save($identity);

        $this->audit->record($userId, 'platform_link.linked', 'platform_link', (string) $identity->getId(), [
            'client' => $instance->getClient(),
            'host' => $instance->getHost(),
            'external_id' => $externalId,
            'previous_user_id' => $previousOwner !== $userId ? $previousOwner : null,
        ], $ip);

        return $identity;
    }

    /**
     * The cloud reports that one of its accounts was deleted, disabled or
     * enabled ({@see PlatformLifecycleService}). A deleted account that was
     * the person's last link with this cloud ends their grant as well, so a
     * refresh token from before cannot come back to life with a later link.
     *
     * @return array{success: bool, account: string, status: string, message: string}
     *
     * @throws PlatformSsoException
     */
    public function lifecycle(PlatformInstance $instance, string $externalId, string $event, string $ip): array
    {
        $identity = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), trim($externalId));
        $userId = $identity?->getUserId();
        $result = $this->lifecycle->apply($instance, $externalId, $event, $ip);
        if ('deleted' === $event && null !== $userId && !$this->hasLink($instance, $userId)) {
            $this->tokens->revokeAll($instance->getInstanceId(), $userId);
        }

        return $result;
    }

    /**
     * The cloud gave up the person's grant. Every token for them ends, and so
     * does the link.
     */
    public function endByCloud(PlatformInstance $instance, int $userId, string $ip): void
    {
        $this->tokens->revokeAll($instance->getInstanceId(), $userId);
        foreach ($this->identities->findForUserOnInstance($userId, $instance->getClient(), $instance->getInstanceId()) as $identity) {
            if (ExternalIdentity::STATUS_REVOKED === $identity->getStatus()) {
                continue;
            }
            $identity->setStatus(ExternalIdentity::STATUS_REVOKED);
            $identity->setRevokedAt(time());
            $this->identities->save($identity);
            $this->effects->onDeleted($identity);
            $this->audit->record($userId, 'platform_link.ended_by_cloud', 'platform_link', (string) $identity->getId(), [
                'client' => $instance->getClient(),
                'host' => $instance->getHost(),
            ], $ip);
        }
    }

    private function hasLink(PlatformInstance $instance, int $userId): bool
    {
        foreach ($this->identities->findForUserOnInstance($userId, $instance->getClient(), $instance->getInstanceId()) as $identity) {
            if (ExternalIdentity::STATUS_REVOKED !== $identity->getStatus()) {
                return true;
            }
        }

        return false;
    }
}
