#!/usr/bin/env php
<?php

use App\Entity\InboundEmailHandler;
use App\Kernel;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;

require __DIR__.'/../vendor/autoload.php';

$kernel = new Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();

/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);

/** @var EncryptionService $encryptionService */
$encryptionService = $container->get(EncryptionService::class);

// Read password from .env
$envFile = __DIR__.'/../.env';
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$password = null;
foreach ($lines as $line) {
    if (str_starts_with(trim($line), '#')) {
        continue;
    }
    if (preg_match('/^GMAIL_PASSWORD=(.+)$/', $line, $matches)) {
        $password = trim($matches[1]);
        break;
    }
}

if (!$password) {
    echo "ERROR: GMAIL_PASSWORD not found in .env\n";
    exit(1);
}

// Find or create handler for user ID 2
$handlerRepo = $em->getRepository(InboundEmailHandler::class);
$existingHandler = $handlerRepo->findOneBy(['userId' => 2]);

if ($existingHandler) {
    echo "Updating existing handler (ID: {$existingHandler->getId()})...\n";
    $handler = $existingHandler;
} else {
    echo "Creating new handler...\n";
    $handler = new InboundEmailHandler();
    $handler->setUserId(2);
    $handler->setName('Gmail Smart Email Handler');
    $handler->setCheckInterval(10);
    $handler->setDeleteAfter(false);
    $handler->setStatus('active');
    $handler->setDepartments([]);
    $handler->setEmailFilter('new', null);
}

// Update with admin@ralfs.ai credentials
$handler->setMailServer('imap.gmail.com');
$handler->setPort(993);
$handler->setProtocol('IMAP');
$handler->setSecurity('SSL/TLS');
$handler->setUsername('admin@ralfs.ai');
$handler->setDecryptedPassword($password, $encryptionService);

$em->persist($handler);
$em->flush();

echo "✅ Handler created/updated successfully!\n";
echo "  ID: {$handler->getId()}\n";
echo "  User ID: {$handler->getUserId()}\n";
echo "  Username: {$handler->getUsername()}\n";
echo "  Server: {$handler->getMailServer()}\n";
echo "  Status: {$handler->getStatus()}\n";
