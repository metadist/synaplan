<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class MailerService
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger
    ) {}

    public function sendVerificationEmail(string $to, string $token): void
    {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'http://localhost:5173';
        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'noreply@synaplan.com';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan';
        
        $verificationUrl = sprintf('%s/verify-email-callback?token=%s', $frontendUrl, $token);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($to)
            ->subject('Verify your email address')
            ->html($this->twig->render('emails/verification.html.twig', [
                'verificationUrl' => $verificationUrl,
            ]));

        try {
            $this->mailer->send($email);
            $this->logger->info('Verification email sent', ['to' => $to]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send verification email', [
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function sendPasswordResetEmail(string $to, string $token): void
    {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'http://localhost:5173';
        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'noreply@synaplan.com';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan';
        
        $resetUrl = sprintf('%s/reset-password?token=%s', $frontendUrl, $token);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($to)
            ->subject('Reset your password')
            ->html($this->twig->render('emails/password-reset.html.twig', [
                'resetUrl' => $resetUrl,
            ]));

        try {
            $this->mailer->send($email);
            $this->logger->info('Password reset email sent', ['to' => $to]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send password reset email', [
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function sendWelcomeEmail(string $to, string $name): void
    {
        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'noreply@synaplan.com';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan';
        
        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($to)
            ->subject('Welcome to Synaplan!')
            ->html($this->twig->render('emails/welcome.html.twig', [
                'name' => $name,
            ]));

        try {
            $this->mailer->send($email);
            $this->logger->info('Welcome email sent', ['to' => $to]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send welcome email', [
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            // Don't throw - welcome email is not critical
        }
    }
}

