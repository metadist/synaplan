<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Loads demo users for development.
 */
class UserFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
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

        $connection = $manager->getConnection();
        $databasePlatform = $connection->getDatabasePlatform();

        // 1. Reset BUSER table and auto-increment
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $connection->executeStatement('TRUNCATE TABLE BUSER');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        foreach ($users as $data) {
            $user = new User();
            $user->setMail($data['mail']);
            $user->setCreated(date('Y-m-d H:i:s'));
            $user->setType($data['type']);
            $user->setUserLevel($data['userLevel']);
            $user->setEmailVerified($data['emailVerified']);
            $user->setUserDetails($data['userDetails']);
            $user->setProviderId('local'); // Set to local
            $user->setPaymentDetails([]);

            // Hash the password
            $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
            $user->setPw($hashedPassword);

            // We use manual SQL to insert with fixed ID to bypass auto-increment
            $connection->executeStatement(
                'INSERT INTO BUSER (BID, BMAIL, BPW, BCREATED, BINTYPE, BUSERLEVEL, BEMAILVERIFIED, BUSERDETAILS, BPAYMENTDETAILS, BPROVIDERID) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $data['id'],
                    $user->getMail(),
                    $user->getPw(),
                    $user->getCreated(),
                    $user->getType(),
                    $user->getUserLevel(),
                    $user->isEmailVerified() ? 1 : 0,
                    json_encode($user->getUserDetails()),
                    json_encode($user->getPaymentDetails()),
                    $user->getProviderId()
                ]
            );
        }

        // Ensure auto-increment starts after our fixed IDs
        $connection->executeStatement('ALTER TABLE BUSER AUTO_INCREMENT = 4');
    }
}
