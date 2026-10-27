<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Service\Admin\AdminUserProvisioningService;

/**
 * Whether deleting a person in one cloud may also delete the Synaplan account.
 * All four conditions of the identity plan must hold. Any miss keeps the account.
 */
final class ProvisionedAccountPolicy
{
    public const NOT_PROVISIONED = 'not_provisioned';

    public const OTHER_IDENTITY = 'other_identity';

    public const PASSWORD = 'password';

    public const ADMIN = 'admin';

    /**
     * Null means this cloud alone manages the account, so it may be deleted.
     *
     * @param list<ExternalIdentity> $identities every identity row of the account
     */
    public function keepReason(User $user, ExternalIdentity $identity, ?PlatformInstance $instance, array $identities): ?string
    {
        if (!$this->provisionedFor($user, $identity, $instance)) {
            return self::NOT_PROVISIONED;
        }

        foreach ($identities as $other) {
            if ($other->getId() === $identity->getId()) {
                continue;
            }
            if (ExternalIdentity::STATUS_ACTIVE === $other->getStatus()) {
                return self::OTHER_IDENTITY;
            }
        }

        if ('' !== $user->getPassword()) {
            return self::PASSWORD;
        }

        if ($user->isAdmin()) {
            return self::ADMIN;
        }

        return null;
    }

    public function sentence(string $reason, ?string $host): string
    {
        return match ($reason) {
            self::OTHER_IDENTITY => 'This account is also used from '.($host ?? 'another connection').'.',
            self::PASSWORD => 'This person can sign in with a password.',
            self::ADMIN => 'This account is an administrator.',
            default => 'This account was not created for this cloud.',
        };
    }

    private function provisionedFor(User $user, ExternalIdentity $identity, ?PlatformInstance $instance): bool
    {
        if (AdminUserProvisioningService::PROVIDER_ID !== $user->getProviderId()) {
            return false;
        }
        $mode = $identity->getMode();
        if (ExternalIdentity::MODE_LINK === $mode || ExternalIdentity::MODE_SSO === $mode) {
            return false;
        }

        $details = $user->getUserDetails();
        $source = $details['external_source'] ?? '';
        $externalId = $details['external_id'] ?? '';
        $client = $instance instanceof PlatformInstance ? $instance->getClient() : $identity->getSource();
        if (!is_string($source) || !is_string($externalId) || $source !== $client) {
            return false;
        }
        if (!$instance instanceof PlatformInstance) {
            return $externalId === $identity->getExternalId();
        }

        if ($externalId === $identity->getExternalId()) {
            return true;
        }

        $serverId = trim((string) ($instance->getServerId() ?? ''));

        return '' !== $serverId && $externalId === $serverId.':'.$identity->getExternalId();
    }
}
