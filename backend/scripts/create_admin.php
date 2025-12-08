#!/usr/bin/env php
<?php

/**
 * Create Admin User Script.
 *
 * Usage: php scripts/create_admin.php email@example.com password123
 */

require_once __DIR__.'/../vendor/autoload.php';

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

// Bootstrap Symfony kernel
$kernel = new App\Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();
$container = $kernel->getContainer();

/** @var EntityManagerInterface $em */
$em = $container->get('doctrine')->getManager();

/** @var UserPasswordHasherInterface $passwordHasher */
$passwordHasher = $container->get(UserPasswordHasherInterface::class);

// Get arguments
if ($argc < 3) {
    echo "Usage: php scripts/create_admin.php email@example.com password123\n";
    exit(1);
}

$email = $argv[1];
$password = $argv[2];

// Check if user already exists
$userRepo = $em->getRepository(User::class);
$existingUser = $userRepo->findOneBy(['mail' => $email]);

if ($existingUser) {
    // Update existing user to admin
    $existingUser->setUserLevel('ADMIN');
    $existingUser->setEmailVerified(true);

    if ($password) {
        $hashedPassword = $passwordHasher->hashPassword($existingUser, $password);
        $existingUser->setPw($hashedPassword);
    }

    $em->flush();

    echo "✅ User '$email' updated to ADMIN level\n";
} else {
    // Create new admin user
    $user = new User();
    $user->setMail($email);
    $user->setType('WEB');
    $user->setProviderId('local');
    $user->setUserLevel('ADMIN');
    $user->setEmailVerified(true);
    $user->setCreated(date('Y-m-d H:i:s'));

    $hashedPassword = $passwordHasher->hashPassword($user, $password);
    $user->setPw($hashedPassword);

    $em->persist($user);
    $em->flush();

    echo "✅ Admin user created successfully!\n";
    echo "   Email: $email\n";
    echo "   Password: $password\n";
    echo "   Level: ADMIN\n";
}

exit(0);
