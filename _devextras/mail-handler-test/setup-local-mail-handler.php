#!/usr/bin/env php
<?php

/**
 * Create or update a local mail handler (Greenmail IMAP → MailHog SMTP forward).
 *
 * Idempotent — safe to run repeatedly. Writes to the same DB table as the UI,
 * so the handler appears at /channels/email for the configured user.
 *
 * Run inside the backend container:
 *   make -C _devextras/mail-handler-test setup
 */

use App\Entity\InboundEmailHandler;
use App\Kernel;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

require '/var/www/backend/vendor/autoload.php';

$kernel = new Kernel('dev', true);
$kernel->boot();

/** @var \Doctrine\Persistence\ManagerRegistry $doctrine */
$doctrine = $kernel->getContainer()->get('doctrine');

/** @var EntityManagerInterface $em */
$em = $doctrine->getManager();

$appSecret = $_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? '';
$encryptionService = new EncryptionService($appSecret, new NullLogger());

$handlerName = 'Local Mail Handler (Greenmail)';
$userId = 1;

$handlerRepo = $em->getRepository(InboundEmailHandler::class);
$handler = $handlerRepo->findOneBy(['userId' => $userId, 'name' => $handlerName]);

if ($handler) {
    echo "Updating existing handler (ID: {$handler->getId()})...\n";
} else {
    echo "Creating new handler...\n";
    $handler = new InboundEmailHandler();
    $handler->setUserId($userId);
    $handler->setName($handlerName);
}

$handler->setMailServer('greenmail');
$handler->setPort(3143);
$handler->setProtocol('IMAP');
$handler->setSecurity('None');
$handler->setUsername('testhandler');
$handler->setDecryptedPassword('testpass', $encryptionService);

$handler->setCheckInterval(1);
$handler->setDeleteAfter(false);
$handler->setStatus('active');
$handler->setEmailFilter('new', null);

$handler->setDepartments([
    [
        'id' => '1',
        'email' => 'sales@test.local',
        'rules' => 'Sales inquiries, orders, pricing, quotes, purchasing',
        'isDefault' => false,
    ],
    [
        'id' => '2',
        'email' => 'support@test.local',
        'rules' => 'Technical support, bugs, issues, complaints, refunds',
        'isDefault' => true,
    ],
    [
        'id' => '3',
        'email' => 'hr@test.local',
        'rules' => 'Job applications, HR matters, hiring, interviews',
        'isDefault' => false,
    ],
]);

$handler->setSmtpCredentials(
    'mailhog',
    1025,
    'noreply@test.local',
    'unused',
    $encryptionService,
    'None',
);

$em->persist($handler);
$em->flush();

echo "\nLocal mail handler ready.\n";
echo "   ID:          {$handler->getId()}\n";
echo "   User:        {$handler->getUserId()}\n";
echo "   UI:          /channels/email\n";
echo "   IMAP:        greenmail:3143\n";
echo "   SMTP fwd:    mailhog:1025\n";
echo "   Departments: sales@test.local, support@test.local (default), hr@test.local\n";
echo "\nNext: make send TYPE=support && make process\n";
echo "Check forwards at http://localhost:8025\n";
