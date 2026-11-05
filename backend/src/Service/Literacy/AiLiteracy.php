<?php

declare(strict_types=1);

namespace App\Service\Literacy;

use App\Entity\AiLiteracyAck;
use App\Entity\User;
use App\Repository\AiLiteracyAckRepository;
use App\Repository\ConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Optional "Using AI well" confirmation. Off by default, and never a gate.
 */
final readonly class AiLiteracy
{
    public const GROUP = 'AI_LITERACY';
    public const KEY_REQUIRED = 'REQUIRED';
    public const KEY_VERSION = 'VERSION';
    public const DEFAULT_VERSION = '2026-11';

    public function __construct(
        private ConfigRepository $config,
        private AiLiteracyAckRepository $acks,
        private EntityManagerInterface $em,
    ) {
    }

    public function isRequired(): bool
    {
        $value = $this->config->getValue(0, self::GROUP, self::KEY_REQUIRED);
        if (null === $value) {
            return false;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }

    public function version(): string
    {
        $value = $this->config->getValue(0, self::GROUP, self::KEY_VERSION);
        $value = null === $value ? '' : trim($value);

        return '' !== $value ? $value : self::DEFAULT_VERSION;
    }

    public function setRequired(bool $required): void
    {
        $this->config->setValue(0, self::GROUP, self::KEY_REQUIRED, $required ? '1' : '0');
        if (null === $this->config->getValue(0, self::GROUP, self::KEY_VERSION)) {
            $this->config->setValue(0, self::GROUP, self::KEY_VERSION, self::DEFAULT_VERSION);
        }
    }

    /**
     * @return array{required: bool, version: string, due: bool}
     */
    public function statusFor(User $user, ?int $now = null): array
    {
        $now ??= time();
        $version = $this->version();
        $required = $this->isRequired();
        $due = false;
        if ($required) {
            $ack = $this->acks->findFor((int) $user->getId(), $version);
            $confirmed = $ack instanceof AiLiteracyAck && null !== $ack->getConfirmedAt();
            $remindAt = $ack instanceof AiLiteracyAck ? $ack->getRemindAt() : null;
            $due = !$confirmed && (null === $remindAt || $remindAt <= $now);
        }

        return ['required' => $required, 'version' => $version, 'due' => $due];
    }

    public function confirm(User $user): void
    {
        $ack = $this->row($user);
        $ack->setConfirmedAt(time());
        $ack->setRemindAt(null);
        $this->acks->save($ack);
    }

    public function remindTomorrow(User $user): void
    {
        $ack = $this->row($user);
        if (null === $ack->getConfirmedAt()) {
            $ack->setRemindAt(time() + 86400);
            $this->acks->save($ack);
        }
    }

    public function countConfirmed(): int
    {
        return $this->acks->countConfirmed($this->version());
    }

    public function countPeople(): int
    {
        return (int) $this->em->getRepository(User::class)->count([]);
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, bool>
     */
    public function confirmedMap(array $userIds): array
    {
        return $this->acks->confirmedMap($userIds, $this->version());
    }

    /**
     * @return list<array{id: int, email: string, confirmed: string}>
     */
    public function exportRows(): array
    {
        $version = $this->version();
        $connection = $this->em->getConnection();
        $sql = 'SELECT u.BID AS id, u.BMAIL AS email, a.BCONFIRMED AS confirmed
            FROM BUSER u
            LEFT JOIN BAI_LITERACY_ACK a ON a.BUSERID = u.BID AND a.BVERSION = :version
            ORDER BY u.BID ASC';
        $statement = $connection->prepare($sql);
        $statement->bindValue('version', $version);
        $rows = [];
        foreach ($statement->executeQuery()->fetchAllAssociative() as $row) {
            $confirmed = $row['confirmed'] ?? null;
            $rows[] = [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'confirmed' => null === $confirmed || '' === (string) $confirmed ? '' : (string) $confirmed,
            ];
        }

        return $rows;
    }

    private function row(User $user): AiLiteracyAck
    {
        $existing = $this->acks->findFor((int) $user->getId(), $this->version());
        if ($existing instanceof AiLiteracyAck) {
            return $existing;
        }

        $ack = new AiLiteracyAck();
        $ack->setUserId((int) $user->getId());
        $ack->setVersion($this->version());

        return $ack;
    }
}
