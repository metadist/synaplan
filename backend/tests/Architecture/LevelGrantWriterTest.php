<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * An account level is written by the grant service. Channel identities stay
 * outside the model: a guest is ANONYMOUS, and a WhatsApp sender steps back
 * to NEW. Billing takes back only its own grant.
 */
final class LevelGrantWriterTest extends TestCase
{
    public function testOnlyTheGrantServiceWritesAnAccountLevel(): void
    {
        $allowed = [
            'Service/Iam/LevelGrantService.php',
            'Service/WhatsApp/Turn/WhatsAppIntake.php',
            'Service/Chat/GuestSessionService.php',
            'Service/Email/EmailChatService.php',
        ];
        $root = dirname(__DIR__, 2).'/src';
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (!str_contains($contents, '->setUserLevel(')) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!in_array($relative, $allowed, true)) {
                $hits[] = $relative;
            }
        }

        self::assertSame([], $hits);
    }

    public function testCancellationTakesBackOnlyTheStripeGrant(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        foreach ([
            'Controller/SubscriptionController.php',
            'Controller/StripeWebhookController.php',
        ] as $relative) {
            $contents = (string) file_get_contents($root.'/'.$relative);
            self::assertStringContainsString('revokeStripe(', $contents, $relative);
            self::assertStringNotContainsString('->setUserLevel(', $contents, $relative);
        }
    }
}
