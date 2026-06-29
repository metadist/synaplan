#!/usr/bin/env php
<?php

/**
 * Send a test email to MailHog for local Smart Email testing.
 *
 * Usage (inside backend container):
 *   php /var/www/smart-email-test/send-smart-email.php
 *   php /var/www/smart-email-test/send-smart-email.php "Create an image of a cat."
 *   php /var/www/smart-email-test/send-smart-email.php --subject=Test --body="Hello"
 *
 *   make -C _devextras/smart-email-test send BODY="Create an image of a cat."
 */

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

require '/var/www/backend/vendor/autoload.php';

$from = 'admin@synaplan.com';
$to = 'smart@synaplan.com';
$subject = 'Smart Email test';
$body = 'Create an image of a red cat.';

foreach ($argv as $i => $arg) {
    if (0 === $i) {
        continue;
    }
    if (str_starts_with($arg, '--from=')) {
        $from = substr($arg, 7);
    } elseif (str_starts_with($arg, '--to=')) {
        $to = substr($arg, 5);
    } elseif (str_starts_with($arg, '--subject=')) {
        $subject = substr($arg, 10);
    } elseif (str_starts_with($arg, '--body=')) {
        $body = substr($arg, 7);
    } elseif (!str_starts_with($arg, '--')) {
        $body = $arg;
    }
}

try {
    $transport = Transport::fromDsn('smtp://mailhog:1025');
    $mailer = new Mailer($transport);

    $email = (new Email())
        ->from($from)
        ->to($to)
        ->subject($subject)
        ->text($body);

    $mailer->send($email);

    echo "\nSmart Email test sent via MailHog\n";
    echo "   From:    {$from}\n";
    echo "   To:      {$to}\n";
    echo "   Subject: {$subject}\n";
    echo "\nRun: make -C _devextras/smart-email-test process\n";
    echo "Or:  make -C _devextras/smart-email-test watch-smart-email\n";
} catch (\Exception $e) {
    echo "\nFailed to send: {$e->getMessage()}\n";
    echo "Is the main stack running? docker compose up -d\n";
    exit(1);
}
