<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\OAuthTokenRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformSsoException;
use App\Service\PlatformLink\LifecycleEffect;
use App\Service\PlatformLink\PlatformLifecycleService;

/**
 * The link between a person and one cloud account, kept in step with that
 * account's OAuth2 grant: redeeming the authorization code creates it, and
 * revoking that account's refresh token ends it. Another account the same
 * person linked on the same cloud is a separate grant.
 */
final readonly class CloudLinkService
{
    public function __construct(
        private ExternalIdentityRepository $identities,
        private OAuthTokenRepository $tokens,
        private ApiKeyRepository $keys,
        private LifecycleEffect $effects,
        private AuditLogWriter $audit,
        private PlatformLifecycleService $lifecycle,
        private CloudGrantMailer $mail,
    ) {
    }

    /**
     * The cloud redeemed the person's code: the cloud account $externalId is
     * theirs. An account another person linked moves, without their key,
     * read scope or watches. An account Synaplan or the identity provider
     * manages does not move.
     *
     * @throws CloudLinkRefused
     */
    public function link(int $userId, PlatformInstance $instance, string $externalId, string $ip): ExternalIdentity
    {
        $externalId = trim($externalId);
        $existing = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId);
        $previousOwner = $existing instanceof ExternalIdentity ? $existing->getUserId() : null;
        if ($existing instanceof ExternalIdentity && in_array($existing->getMode(), [ExternalIdentity::MODE_PROVISION, ExternalIdentity::MODE_SSO], true)) {
            throw new CloudLinkRefused('This cloud account cannot be linked.');
        }
        if ($existing instanceof ExternalIdentity && null !== $previousOwner && $previousOwner !== $userId) {
            $this->tokens->revokeForAccount($instance->getInstanceId(), $previousOwner, $externalId);
            $this->releaseKey($existing);
            $existing->setReadScope(['mode' => 'watched', 'folders' => []]);
            $this->effects->onDeleted($existing);
        }

        $identity = $this->identities->upsert($userId, $instance->getClient(), $externalId, $instance->getInstanceId(), null, ExternalIdentity::MODE_LINK);
        if (null !== $previousOwner && $previousOwner !== $userId) {
            $identity->setApiKeyId(null);
        }
        $identity->setStatus(ExternalIdentity::STATUS_ACTIVE);
        $identity->setRevokedAt(null);
        $this->identities->save($identity);

        $moved = null !== $previousOwner && $previousOwner !== $userId;
        if ($moved) {
            foreach ([$previousOwner, $userId] as $actor) {
                $this->audit->record($actor, 'platform_link.reassigned', 'platform_link', (string) $identity->getId(), [
                    'client' => $instance->getClient(),
                    'host' => $instance->getHost(),
                    'external_id' => $externalId,
                    'previous_user_id' => $previousOwner,
                    'new_user_id' => $userId,
                ], $ip);
            }
            $this->mail->notify($previousOwner, $instance, $externalId, true);
        } else {
            $this->audit->record($userId, 'platform_link.linked', 'platform_link', (string) $identity->getId(), [
                'client' => $instance->getClient(),
                'host' => $instance->getHost(),
                'external_id' => $externalId,
            ], $ip);
        }
        if (null === $previousOwner || $moved) {
            $this->mail->notify($userId, $instance, $externalId, false);
        }

        return $identity;
    }

    /**
     * The cloud reports that one of its accounts was deleted, disabled or
     * enabled ({@see PlatformLifecycleService}). Deleting the account ends
     * that account's grant, and no other account's.
     *
     * @return array{success: bool, account: string, status: string, message: string}
     *
     * @throws PlatformSsoException
     */
    public function lifecycle(PlatformInstance $instance, string $externalId, string $event, string $ip): array
    {
        $externalId = trim($externalId);
        $identity = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId);
        $userId = $identity?->getUserId();
        $result = $this->lifecycle->apply($instance, $externalId, $event, $ip);
        if ('deleted' === $event && null !== $userId) {
            $this->tokens->revokeForAccount($instance->getInstanceId(), $userId, $externalId);
        }

        return $result;
    }

    /**
     * The cloud gave up one account's grant. That account's tokens and link
     * end. The person's other accounts on this cloud stay.
     */
    public function endByCloud(PlatformInstance $instance, int $userId, string $externalId, string $ip): void
    {
        $this->tokens->revokeForAccount($instance->getInstanceId(), $userId, $externalId);
        $identity = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId);
        if (!$identity instanceof ExternalIdentity || $identity->getUserId() !== $userId || ExternalIdentity::STATUS_REVOKED === $identity->getStatus()) {
            return;
        }
        $identity->setStatus(ExternalIdentity::STATUS_REVOKED);
        $identity->setRevokedAt(time());
        $this->identities->save($identity);
        $this->effects->onDeleted($identity);
        $this->audit->record($userId, 'platform_link.ended_by_cloud', 'platform_link', (string) $identity->getId(), [
            'client' => $instance->getClient(),
            'host' => $instance->getHost(),
            'external_id' => $externalId,
        ], $ip);
    }

    private function releaseKey(ExternalIdentity $identity): void
    {
        $keyId = $identity->getApiKeyId();
        if (null === $keyId) {
            return;
        }
        $key = $this->keys->find($keyId);
        if ($key instanceof ApiKey) {
            $this->keys->remove($key, false);
        }
        $identity->setApiKeyId(null);
    }
}
