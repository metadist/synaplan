<?php

declare(strict_types=1);

namespace App\Tests\Trait;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates rows for a functional test and removes them afterwards, newest
 * first. The using test sets $em and calls removeCreatedEntities() in its
 * tearDown().
 */
trait CreatesTestEntities
{
    private EntityManagerInterface $em;

    /** @var list<object> in creation order */
    private array $createdEntities = [];

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function persistEntity(object $entity): object
    {
        $this->em->persist($entity);
        $this->em->flush();
        $this->createdEntities[] = $entity;

        return $entity;
    }

    private function createUser(string $name): User
    {
        return $this->persistEntity((new User())
            ->setMail($name.'-'.bin2hex(random_bytes(4)).'@functional.test')
            ->setCreated(date('YmdHis'))
            ->setType('WEB')
            ->setProviderId('local')
            ->setUserLevel('NEW'));
    }

    private function removeCreatedEntities(): void
    {
        $this->em->clear();
        foreach (array_reverse($this->createdEntities) as $entity) {
            $found = $this->em->find($entity::class, $entity->getId());
            if (null !== $found) {
                $this->em->remove($found);
            }
        }
        $this->em->flush();
        $this->createdEntities = [];
    }
}
