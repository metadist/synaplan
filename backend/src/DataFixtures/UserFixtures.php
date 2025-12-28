<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Loads demo users for development with fixed IDs (1, 2, 3).
 *
 * Uses raw SQL INSERT to ensure consistent IDs regardless of auto-increment state.
 * The table is empty at this point because the purger has already run.
 */
class UserFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $users = [
            [
                'id' => 1,
                'mail' => 'admin@synaplan.com',
                'password' => 'admin123',
                'userLevel' => 'ADMIN',
                'emailVerified' => true,
                'type' => 'WEB',
                'userDetails' => [
                    'firstName' => 'Admin',
                    'lastName' => 'User',
                    'company' => 'Synaplan',
                ],
            ],
            [
                'id' => 2,
                'mail' => 'demo@synaplan.com',
                'password' => 'demo123',
                'userLevel' => 'PRO',
                'emailVerified' => true,
                'type' => 'WEB',
                'userDetails' => [
                    'firstName' => 'Demo',
                    'lastName' => 'User',
                ],
            ],
            [
                'id' => 3,
                'mail' => 'test@example.com',
                'password' => 'test123',
                'userLevel' => 'NEW',
                'emailVerified' => false,
                'type' => 'WEB',
                'userDetails' => [
                    'firstName' => 'Test',
                    'lastName' => 'User',
                ],
            ],
        ];

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Expected EntityManagerInterface');
        }
        $connection = $manager->getConnection();

        foreach ($users as $data) {
            // Create user entity to hash password
            $user = new User();
            $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);

            // Use raw SQL INSERT with explicit ID to bypass auto-increment
            // Table is empty at this point (purger already ran DELETE)
            $connection->executeStatement(
                'INSERT INTO BUSER (BID, BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID, BUSERLEVEL, BEMAILVERIFIED, BUSERDETAILS, BPAYMENTDETAILS) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $data['id'],
                    date('Y-m-d H:i:s'),
                    $data['type'],
                    $data['mail'],
                    $hashedPassword,
                    'local',
                    $data['userLevel'],
                    $data['emailVerified'] ? 1 : 0,
                    json_encode($data['userDetails']),
                    '[]',
                ]
            );
        }
    }
}
