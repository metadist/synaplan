<?php

namespace App\Service;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Log\LoggerInterface;

/**
 * Multilingual Email Service
 * 
 * Handles sending emails in the user's preferred language
 */
class EmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private string $fromEmail = 'noreply@synaplan.com',
        private string $fromName = 'Synaplan'
    ) {}

    /**
     * Send verification email
     * 
     * @param string $to Recipient email address
     * @param string $verificationUrl Verification URL
     * @param string $locale Language code (en, de, fr, etc.)
     */
    public function sendVerificationEmail(
        string $to,
        string $verificationUrl,
        string $locale = 'en'
    ): void {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($to)
            ->subject($this->getTranslation('email.verification.title', $locale))
            ->htmlTemplate('emails/verification.html.twig')
            ->locale($locale)
            ->context([
                'verificationUrl' => $verificationUrl,
            ]);

        $this->send($email, 'verification', $to, $locale);
    }

    /**
     * Send password reset email
     * 
     * @param string $to Recipient email address
     * @param string $resetUrl Password reset URL
     * @param string $locale Language code (en, de, fr, etc.)
     */
    public function sendPasswordResetEmail(
        string $to,
        string $resetUrl,
        string $locale = 'en'
    ): void {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($to)
            ->subject($this->getTranslation('email.password_reset.title', $locale))
            ->htmlTemplate('emails/password-reset.html.twig')
            ->locale($locale)
            ->context([
                'resetUrl' => $resetUrl,
            ]);

        $this->send($email, 'password-reset', $to, $locale);
    }

    /**
     * Send welcome email
     * 
     * @param string $to Recipient email address
     * @param string $name User's name
     * @param string $locale Language code (en, de, fr, etc.)
     * @param string|null $appUrl Application URL
     */
    public function sendWelcomeEmail(
        string $to,
        string $name,
        string $locale = 'en',
        ?string $appUrl = null
    ): void {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($to)
            ->subject($this->getTranslation('email.welcome.title', $locale))
            ->htmlTemplate('emails/welcome.html.twig')
            ->locale($locale)
            ->context([
                'name' => $name,
                'app_url' => $appUrl ?? 'http://localhost:3000',
            ]);

        $this->send($email, 'welcome', $to, $locale);
    }

    /**
     * Send generic templated email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $template Template path (e.g., 'emails/custom.html.twig')
     * @param array $context Template context variables
     * @param string $locale Language code
     */
    public function sendTemplatedEmail(
        string $to,
        string $subject,
        string $template,
        array $context = [],
        string $locale = 'en'
    ): void {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template)
            ->locale($locale)
            ->context($context);

        $this->send($email, $template, $to, $locale);
    }

    /**
     * Internal method to send email and log
     */
    private function send(
        TemplatedEmail $email,
        string $type,
        string $recipient,
        string $locale
    ): void {
        try {
            $this->mailer->send($email);
            
            $this->logger->info('Email sent successfully', [
                'type' => $type,
                'recipient' => $recipient,
                'locale' => $locale
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email', [
                'type' => $type,
                'recipient' => $recipient,
                'locale' => $locale,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Get translation for a key in specific locale
     */
    private function getTranslation(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], 'emails', $locale);
    }
}

