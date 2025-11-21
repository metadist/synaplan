<?php

namespace App\Tests\Unit;

use App\Service\InternalEmailService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

class InternalEmailServiceTest extends TestCase
{
    public function testVerificationEmailStructure(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Expect send to be called once
        $mailer->expects($this->once())
            ->method('send');

        $service = new InternalEmailService($mailer, $twig, $logger);
        $service->sendVerificationEmail('test@example.com', 'test_token_123');

        $this->assertTrue(true, 'Verification email method called successfully');
    }

    public function testPasswordResetEmailStructure(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class);
        $logger = $this->createMock(LoggerInterface::class);

        $mailer->expects($this->once())
            ->method('send');

        $service = new InternalEmailService($mailer, $twig, $logger);
        $service->sendPasswordResetEmail('test@example.com', 'reset_token_456');

        $this->assertTrue(true, 'Password reset email method called successfully');
    }
}

