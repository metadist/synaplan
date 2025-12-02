<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Loads demo users for development
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
                'mail' => 'admin@synaplan.com',
                'password' => 'admin123',
                'userLevel' => 'ADMIN',
                'emailVerified' => true,
                'type' => 'WEB',
                'userDetails' => [
                    'firstName' => 'Admin',
                    'lastName' => 'User',
                    'company' => 'Synaplan'
                ]
            ],
            [
                'mail' => 'demo@synaplan.com',
                'password' => 'demo123',
                'userLevel' => 'PRO',
                'emailVerified' => true,
                'type' => 'WEB',
                'userDetails' => [
                    'firstName' => 'Demo',
                    'lastName' => 'User'
                ]
            ],
            [
                'mail' => 'test@example.com',
                'password' => 'test123',
                'userLevel' => 'NEW',
                'emailVerified' => false,
                'type' => 'WEB',
                'userDetails' => [
                    'firstName' => 'Test',
                    'lastName' => 'User'
                ]
            ]
        ];

        foreach ($users as $data) {
            $user = new User();
            $user->setMail($data['mail']);
            $user->setCreated(date('Y-m-d H:i:s'));
            $user->setType($data['type']);
            $user->setUserLevel($data['userLevel']);
            $user->setEmailVerified($data['emailVerified']);
            $user->setUserDetails($data['userDetails']);
            $user->setProviderId(''); // Empty for local users
            $user->setPaymentDetails([]);
            
            // Hash the password
            $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
            $user->setPw($hashedPassword);
            
            $manager->persist($user);
        }

        $manager->flush();
    }
}

