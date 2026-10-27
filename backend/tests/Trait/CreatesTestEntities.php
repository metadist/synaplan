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
            // A row the test deleted through the API has lost its id.
            $id = $entity->getId();
            $found = null === $id ? null : $this->em->find($entity::class, $id);
            if (null !== $found) {
                if ($found instanceof User) {
                    // Chat calls now write a usage row. BUSELOG has no cascade,
                    // so the row has to go before the user.
                    $this->em->getConnection()->executeStatement('DELETE FROM BUSELOG WHERE BUSERID = ?', [$id]);
                }
                $this->em->remove($found);
            }
        }
        $this->em->flush();
        $this->createdEntities = [];
    }
}
