#!/usr/bin/env php
<?php

/**
 * Send a test email to Greenmail for local mail-handler testing.
 *
 * Usage (inside backend container):
 *   php /var/www/mail-test/send-test-email.php
 *   php /var/www/mail-test/send-test-email.php --type=sales
 *   php /var/www/mail-test/send-test-email.php "Custom subject" "Body text"
 *
 *   make -C _devextras/mail-handler-test send TYPE=support
 */

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

require '/var/www/backend/vendor/autoload.php';

$presets = [
    'sales' => [
        'from' => 'customer@acme-corp.com',
        'subject' => 'Request for enterprise pricing',
        'body' => "Hello,\n\nWe are interested in purchasing a license for 50 users.\nCould you send us a quote for the enterprise plan?\n\nBest regards,\nJohn Miller\nACME Corp",
    ],
    'support' => [
        'from' => 'jane.doe@example.com',
        'subject' => 'Bug: File upload fails with error 500',
        'body' => "Hi support team,\n\nWhen I try to upload a PDF file larger than 10MB, I get a server error (500).\nThis started happening after the latest update.\n\nBrowser: Chrome 126\nOS: Windows 11\n\nPlease help!\nJane",
    ],
    'hr' => [
        'from' => 'maria.garcia@gmail.com',
        'subject' => 'Application for Senior Developer position',
        'body' => "Dear Hiring Team,\n\nI would like to apply for the Senior Developer position posted on your website.\nI have 8 years of experience with PHP/Symfony and Vue.js.\n\nPlease find my CV attached.\n\nBest regards,\nMaria Garcia",
    ],
    'spam' => [
        'from' => 'no-reply@newsletter.spam.com',
        'subject' => 'You have won $1,000,000!!!',
        'body' => "CONGRATULATIONS! You are the lucky winner of our sweepstakes!\nClick here to claim your prize: http://totally-not-a-scam.com\n\nThis is an automated message. Do not reply.",
    ],
];

$type = null;
$subject = null;
$body = null;

foreach ($argv as $i => $arg) {
    if (0 === $i) {
        continue;
    }
    if (str_starts_with($arg, '--type=')) {
        $type = substr($arg, 7);
    } elseif (null === $subject) {
        $subject = $arg;
    } elseif (null === $body) {
        $body = $arg;
    }
}

if ($type && isset($presets[$type])) {
    $preset = $presets[$type];
    $from = $preset['from'];
    $subject = $preset['subject'];
    $body = $preset['body'];
    echo "Using preset: {$type}\n";
} elseif ($type && !isset($presets[$type])) {
    echo "Unknown preset: {$type}\n";
    echo 'Available: '.implode(', ', array_keys($presets))."\n";
    exit(1);
} else {
    $from = 'customer@example.com';
    $subject ??= 'Help needed with my order #12345';
    $body ??= "Hello,\n\nI have a problem with my recent order.\nThe product arrived damaged and I need a replacement.\n\nOrder number: #12345\nProduct: Widget Pro X\n\nPlease advise.\nThanks";
}

try {
    $transport = Transport::fromDsn('smtp://greenmail:3025');
    $mailer = new Mailer($transport);

    $email = (new Email())
        ->from($from)
        ->to('testhandler@test.local')
        ->subject($subject)
        ->text($body);

    $mailer->send($email);

    echo "\nTest email sent to Greenmail (testhandler@test.local)\n";
    echo "   From:    {$from}\n";
    echo "   Subject: {$subject}\n";
    echo "\nRun: make -C _devextras/mail-handler-test process\n";
} catch (\Exception $e) {
    echo "\nFailed to send: {$e->getMessage()}\n";
    echo "Is Greenmail running? make -C _devextras/mail-handler-test up\n";
    exit(1);
}
