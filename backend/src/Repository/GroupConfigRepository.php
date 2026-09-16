<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupConfig>
 */
class GroupConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupConfig::class);
    }

    /**
     * @param list<int> $groupIds
     *
     * @return list<GroupConfig>
     */
    public function getForGroups(array $groupIds, string $group, string $setting): array
    {
        if ([] === $groupIds) {
            return [];
        }

        /** @var list<GroupConfig> $rows */
        $rows = $this->createQueryBuilder('c')
            ->where('c.groupId IN (:ids)')
            ->andWhere('c.group = :group')
            ->andWhere('c.setting = :setting')
            ->setParameter('ids', $groupIds)
            ->setParameter('group', $group)
            ->setParameter('setting', $setting)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function getValue(int $groupId, string $group, string $setting): ?string
    {
        $row = $this->findOneBy([
            'groupId' => $groupId,
            'group' => $group,
            'setting' => $setting,
        ]);

        return $row?->getValue();
    }

    public function setValue(int $groupId, string $group, string $setting, string $value): GroupConfig
    {
        $row = $this->findOneBy([
            'groupId' => $groupId,
            'group' => $group,
            'setting' => $setting,
        ]);
        if (!$row) {
            $row = new GroupConfig();
            $row->setGroupId($groupId);
            $row->setGroup($group);
            $row->setSetting($setting);
        }
        $row->setValue($value);
        $this->getEntityManager()->persist($row);
        $this->getEntityManager()->flush();

        return $row;
    }

    public function deleteValue(int $groupId, string $group, string $setting): bool
    {
        $row = $this->findOneBy([
            'groupId' => $groupId,
            'group' => $group,
            'setting' => $setting,
        ]);
        if (!$row) {
            return false;
        }
        $this->getEntityManager()->remove($row);
        $this->getEntityManager()->flush();

        return true;
    }

    public function deleteByGroupId(int $groupId): void
    {
        $this->createQueryBuilder('c')
            ->delete()
            ->where('c.groupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->execute();
    }

    /**
     * @return list<GroupConfig>
     */
    public function findByGroupAndSetting(string $group, string $setting): array
    {
        /** @var list<GroupConfig> $rows */
        $rows = $this->findBy(['group' => $group, 'setting' => $setting]);

        return $rows;
    }
}
