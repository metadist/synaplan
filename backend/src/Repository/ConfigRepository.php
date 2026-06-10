<?php

namespace App\Repository;

use App\Entity\Config;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Config::class);
    }

    /**
     * Get configuration value.
     */
    public function getValue(int $ownerId, string $group, string $setting): ?string
    {
        $config = $this->findOneBy([
            'ownerId' => $ownerId,
            'group' => $group,
            'setting' => $setting,
        ]);

        return $config?->getValue();
    }

    /**
     * Get all configs for a group and owner (with fallback to owner=0).
     */
    public function getByGroup(int $ownerId, string $group): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.group = :group')
            ->andWhere('c.ownerId IN (:ownerIds)')
            ->setParameter('group', $group)
            ->setParameter('ownerIds', [0, $ownerId])
            ->orderBy('c.ownerId', 'DESC') // User-specific configs override defaults
            ->getQuery()
            ->getResult();
    }

    /**
     * Set configuration value (upsert).
     */
    public function setValue(int $ownerId, string $group, string $setting, string $value): Config
    {
        $config = $this->findOneBy([
            'ownerId' => $ownerId,
            'group' => $group,
            'setting' => $setting,
        ]);

        if (!$config) {
            $config = new Config();
            $config->setOwnerId($ownerId);
            $config->setGroup($group);
            $config->setSetting($setting);
        }

        $config->setValue($value);
        $this->getEntityManager()->persist($config);
        $this->getEntityManager()->flush();

        return $config;
    }

    /**
     * Delete a configuration row if it exists (no-op when absent).
     *
     * Returns true when a row was removed. Used to drop a per-user override so
     * the owner falls back to the global/default value again.
     */
    public function deleteValue(int $ownerId, string $group, string $setting): bool
    {
        $config = $this->findOneBy([
            'ownerId' => $ownerId,
            'group' => $group,
            'setting' => $setting,
        ]);

        if (!$config) {
            return false;
        }

        $this->getEntityManager()->remove($config);
        $this->getEntityManager()->flush();

        return true;
    }

    /**
     * Find config by owner, group and setting.
     */
    public function findByOwnerGroupAndSetting(int $ownerId, string $group, string $setting): ?Config
    {
        return $this->findOneBy([
            'ownerId' => $ownerId,
            'group' => $group,
            'setting' => $setting,
        ]);
    }

    /**
     * Save config.
     */
    public function save(Config $config, bool $flush = true): void
    {
        $this->getEntityManager()->persist($config);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove config.
     */
    public function remove(Config $config, bool $flush = true): void
    {
        $this->getEntityManager()->remove($config);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove multiple configs in a single flush.
     *
     * @param Config[] $configs
     */
    public function removeAll(array $configs): void
    {
        $em = $this->getEntityManager();
        foreach ($configs as $config) {
            $em->remove($config);
        }
        $em->flush();
    }
}
