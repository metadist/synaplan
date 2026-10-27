<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ApiKey;
use App\Entity\AuditLogEntry;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Repository\UserRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\Iam\UserDeletionService;
use App\Service\PlatformLink\Exception\PlatformSsoException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A cloud reporting that one of its people was deleted, disabled or enabled.
 * The Synaplan account goes only while that cloud alone manages it.
 */
final readonly class PlatformLifecycleService
{
    public function __construct(
        private ExternalIdentityRepository $identities,
        private UserRepository $users,
        private ApiKeyRepository $apiKeys,
        private PlatformInstanceRepository $instances,
        private ProvisionedAccountPolicy $policy,
        private UserDeletionService $deletion,
        private AuditLogWriter $audit,
        private DeliveryNotifier $notifier,
        private LifecycleEffect $effects,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The generic admin delete, called with an API key. A session admin does
     * not come through here.
     *
     * @return array{deleted: bool, message: string}
     */
    public function deleteCalledByApiKey(User $target, string $ip): array
    {
        $identity = $this->provisionedIdentity($target);
        if (!$identity instanceof ExternalIdentity) {
            return ['deleted' => false, 'message' => $this->policy->sentence(ProvisionedAccountPolicy::NOT_PROVISIONED, null)];
        }

        if ('' === $identity->getInstanceId()) {
            $result = $this->finish($identity, null, 'deleted', $ip);

            return [
                'deleted' => 'deleted' === $result['account'],
                'message' => $result['message'],
            ];
        }

        $instance = $this->instances->findByInstanceId($identity->getInstanceId());
        if (!$instance instanceof PlatformInstance) {
            return ['deleted' => false, 'message' => $this->policy->sentence(ProvisionedAccountPolicy::NOT_PROVISIONED, null)];
        }

        $result = $this->apply($instance, $identity->getExternalId(), 'deleted', $ip);

        return [
            'deleted' => 'deleted' === $result['account'],
            'message' => $result['message'],
        ];
    }

    /**
     * @return array{success: bool, account: string, status: string, message: string}
     */
    public function apply(PlatformInstance $instance, string $externalId, string $event, string $ip): array
    {
        $externalId = trim($externalId);
        if ('' === $externalId) {
            throw new PlatformSsoException(Response::HTTP_BAD_REQUEST, 'invalid_external_id', 'A platform user id is required.');
        }
        if (!in_array($event, ['deleted', 'disabled', 'enabled'], true)) {
            throw new PlatformSsoException(Response::HTTP_BAD_REQUEST, 'invalid_event', 'The event must be deleted, disabled or enabled.');
        }

        $row = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId);
        if (!$row instanceof ExternalIdentity) {
            return $this->answer('absent', ExternalIdentity::STATUS_REVOKED, 'This person is not linked.');
        }

        return $this->finish($row, $instance, $event, $ip);
    }

    /**
     * @return array{success: bool, account: string, status: string, message: string}
     */
    private function finish(ExternalIdentity $row, ?PlatformInstance $instance, string $event, string $ip): array
    {
        if ('enabled' === $event && ExternalIdentity::STATUS_REVOKED === $row->getStatus()) {
            throw new PlatformSsoException(Response::HTTP_CONFLICT, 'identity_revoked', 'This person was removed from the cloud.');
        }

        /** @var array{success: bool, account: string, status: string, message: string} $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($row, $instance, $event, $ip): array {
            $this->entityManager->lock($row, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($row);

            return $this->applyLocked($row, $instance, $event, $ip);
        });

        return $result;
    }

    /**
     * @return array{success: bool, account: string, status: string, message: string}
     */
    private function applyLocked(ExternalIdentity $row, ?PlatformInstance $instance, string $event, string $ip): array
    {
        $user = $this->users->find($row->getUserId());
        if ('deleted' === $event && ExternalIdentity::STATUS_REVOKED === $row->getStatus()) {
            if (!$user instanceof User) {
                return $this->answer('absent', ExternalIdentity::STATUS_REVOKED, 'This person is not linked.');
            }
            if ($this->wasKept($row)) {
                return $this->answer('unchanged', ExternalIdentity::STATUS_REVOKED, 'Nothing changed.');
            }
        } elseif ($this->already($row, $event)) {
            return $this->answer('unchanged', $row->getStatus(), 'Nothing changed.');
        }

        if (!$user instanceof User) {
            $this->mark($row, ExternalIdentity::STATUS_REVOKED);

            return $this->answer('absent', ExternalIdentity::STATUS_REVOKED, 'This person is not linked.');
        }

        if ('disabled' === $event) {
            $this->mark($row, ExternalIdentity::STATUS_PAUSED);
            $this->effects->onPaused($row);
            $this->record($user, $row, 'platform_link.disabled', $ip, []);

            return $this->answer('kept', ExternalIdentity::STATUS_PAUSED, 'Synaplan is paused for this person.');
        }

        if ('enabled' === $event) {
            $this->mark($row, ExternalIdentity::STATUS_ACTIVE);
            $this->effects->onResumed($row);
            $this->record($user, $row, 'platform_link.enabled', $ip, []);

            return $this->answer('kept', ExternalIdentity::STATUS_ACTIVE, 'Synaplan is on again for this person.');
        }

        $this->entityManager->refresh($row);
        $this->revokeBoundKey($row);
        $this->mark($row, ExternalIdentity::STATUS_REVOKED);
        $this->effects->onDeleted($row);

        $identities = $this->identities->findByUserId((int) $user->getId());
        $reason = $this->policy->keepReason($user, $row, $instance, $identities);
        if (null !== $reason) {
            $message = $this->policy->sentence($reason, $this->otherHost($user, $row));
            $this->record($user, $row, 'platform_link.account_kept', $ip, ['reason' => $reason, 'message' => $message]);
            $this->notifier->identityRevoked($row);

            return $this->answer('kept', ExternalIdentity::STATUS_REVOKED, $message);
        }

        $deleted = $this->deletion->deleteUserIf($user, function (User $fresh) use ($row, $instance): bool {
            $again = $this->identities->findByUserId((int) $fresh->getId());

            return null === $this->policy->keepReason($fresh, $row, $instance, $again);
        });
        if (!$deleted) {
            $message = $this->policy->sentence(ProvisionedAccountPolicy::ADMIN, null);
            $this->record($user, $row, 'platform_link.account_kept', $ip, ['reason' => ProvisionedAccountPolicy::ADMIN, 'message' => $message]);
            $this->notifier->identityRevoked($row);

            return $this->answer('kept', ExternalIdentity::STATUS_REVOKED, $message);
        }

        $this->record($user, $row, 'platform_link.account_deleted', $ip, []);
        $this->notifier->identityRevoked($row);

        return $this->answer('deleted', ExternalIdentity::STATUS_REVOKED, 'The account was removed.');
    }

    private function wasKept(ExternalIdentity $row): bool
    {
        $found = $this->entityManager->getRepository(AuditLogEntry::class)->findOneBy([
            'action' => 'platform_link.account_kept',
            'resourceKind' => 'platform_link',
            'resourceId' => (string) $row->getId(),
        ]);

        return $found instanceof AuditLogEntry;
    }

    private function already(ExternalIdentity $row, string $event): bool
    {
        $status = $row->getStatus();

        return ('deleted' === $event && ExternalIdentity::STATUS_REVOKED === $status)
            || ('disabled' === $event && ExternalIdentity::STATUS_PAUSED === $status)
            || ('disabled' === $event && ExternalIdentity::STATUS_REVOKED === $status)
            || ('enabled' === $event && ExternalIdentity::STATUS_ACTIVE === $status);
    }

    private function mark(ExternalIdentity $row, string $status): void
    {
        $row->setStatus($status);
        if (ExternalIdentity::STATUS_REVOKED === $status) {
            $row->setRevokedAt(time());
        }
        $this->entityManager->flush();
    }

    private function revokeBoundKey(ExternalIdentity $row): void
    {
        $keyId = $row->getApiKeyId();
        if (null === $keyId) {
            return;
        }
        $key = $this->apiKeys->find($keyId);
        if ($key instanceof ApiKey && $key->isActive()) {
            $key->setStatus('revoked');
        }
    }

    private function provisionedIdentity(User $user): ?ExternalIdentity
    {
        $details = $user->getUserDetails();
        $source = $details['external_source'] ?? '';
        $externalId = $details['external_id'] ?? '';
        if (!is_string($source) || !is_string($externalId) || '' === $source || '' === $externalId) {
            return null;
        }

        foreach ($this->identities->findByUserId((int) $user->getId()) as $row) {
            if ($row->getSource() !== $source) {
                continue;
            }
            if ($row->getExternalId() === $externalId || str_ends_with($externalId, ':'.$row->getExternalId())) {
                return $row;
            }
        }

        return null;
    }

    private function otherHost(User $user, ExternalIdentity $identity): ?string
    {
        foreach ($this->identities->findByUserId((int) $user->getId()) as $other) {
            if ($other->getId() === $identity->getId() || ExternalIdentity::STATUS_ACTIVE !== $other->getStatus()) {
                continue;
            }
            if ('' === $other->getInstanceId()) {
                return $other->getSource();
            }
            $instance = $this->instances->findByInstanceId($other->getInstanceId());

            return $instance instanceof PlatformInstance ? $instance->getHost() : $other->getSource();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(User $user, ExternalIdentity $row, string $action, string $ip, array $details): void
    {
        $this->audit->record(
            (int) $user->getId(),
            $action,
            'platform_link',
            (string) $row->getId(),
            $details,
            $ip,
        );
    }

    /**
     * @return array{success: bool, account: string, status: string, message: string}
     */
    private function answer(string $account, string $status, string $message): array
    {
        return [
            'success' => true,
            'account' => $account,
            'status' => $status,
            'message' => $message,
        ];
    }
}
