<?php

namespace App\Service;

use Parsedown;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Internal Email Service.
 *
 * Handles system emails for authentication (verification, password reset, welcome).
 * Uses SMTP configuration from environment variables (MAILER_DSN).
 * Supports multilingual emails based on user locale.
 */
class InternalEmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Send email verification link.
     */
    public function sendVerificationEmail(string $to, string $token, string $locale = 'en'): void
    {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'http://localhost:5173';
        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'noreply@synaplan.com';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan';

        $verificationUrl = sprintf('%s/verify-email-callback?token=%s', $frontendUrl, $token);

        // Translate subject
        $subject = $this->translator->trans('email.verification.title', [], 'emails', $locale);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($to)
            ->subject($subject)
            ->html($this->renderTemplate('emails/verification.html.twig', [
                'verificationUrl' => $verificationUrl,
            ], $locale));

        try {
            $this->mailer->send($email);
            $this->logger->info('Verification email sent', ['to' => $to, 'locale' => $locale]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send verification email', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Send password reset link.
     */
    public function sendPasswordResetEmail(string $to, string $token, string $locale = 'en'): void
    {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'http://localhost:5173';
        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'noreply@synaplan.com';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan';

        $resetUrl = sprintf('%s/reset-password?token=%s', $frontendUrl, $token);

        // Translate subject
        $subject = $this->translator->trans('email.password_reset.title', [], 'emails', $locale);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($to)
            ->subject($subject)
            ->html($this->renderTemplate('emails/password-reset.html.twig', [
                'resetUrl' => $resetUrl,
            ], $locale));

        try {
            $this->mailer->send($email);
            $this->logger->info('Password reset email sent', ['to' => $to, 'locale' => $locale]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send password reset email', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Send welcome email after email verification.
     */
    public function sendWelcomeEmail(string $to, string $name, string $locale = 'en'): void
    {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'http://localhost:5173';
        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'noreply@synaplan.com';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan';

        // Translate subject
        $subject = $this->translator->trans('email.welcome.title', [], 'emails', $locale);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($to)
            ->subject($subject)
            ->html($this->renderTemplate('emails/welcome.html.twig', [
                'name' => $name,
                'app_url' => $frontendUrl,
            ], $locale));

        try {
            $this->mailer->send($email);
            $this->logger->info('Welcome email sent', ['to' => $to, 'locale' => $locale]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send welcome email', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - welcome email is not critical
        }
    }

    /**
     * Send AI response email (for smart@synaplan.net chat).
     *
     * @param string      $to             Recipient email address
     * @param string      $subject        Original email subject
     * @param string      $bodyText       AI response text (markdown format)
     * @param string|null $inReplyTo      Message ID for email threading
     * @param string|null $provider       AI provider name (e.g., 'ollama', 'openai')
     * @param string|null $model          AI model name (e.g., 'llama3.2')
     * @param float|null  $processingTime Processing time in seconds
     */
    public function sendAiResponseEmail(
        string $to,
        string $subject,
        string $bodyText,
        ?string $inReplyTo = null,
        ?string $provider = null,
        ?string $model = null,
        ?float $processingTime = null,
    ): void {
        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'smart@synaplan.net';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan AI';

        // Convert markdown to HTML using Parsedown
        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(true); // Prevent XSS
        $htmlBody = $parsedown->text($bodyText);

        // Add metadata footer if available
        if ($provider || $model || null !== $processingTime) {
            $metadataParts = [];
            if ($provider) {
                $metadataParts[] = 'Service: '.htmlspecialchars($provider);
            }
            if ($model) {
                $metadataParts[] = 'Model: '.htmlspecialchars($model);
            }
            if (null !== $processingTime) {
                $metadataParts[] = sprintf('Processing time: %.2f seconds', $processingTime);
            }

            if (!empty($metadataParts)) {
                $htmlBody .= '<br><br><div style="font-size: 11px; color: #888888; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e0e0e0;">';
                $htmlBody .= implode(' &middot; ', $metadataParts);
                $htmlBody .= '<br><a href="https://www.synaplan.com/" style="color: #888888;">www.synaplan.com</a>';
                $htmlBody .= '</div>';
            }
        }

        // Create plain text version (use original markdown for better readability)
        $textBody = $bodyText;
        // Add metadata footer to plain text as well
        if ($provider || $model || null !== $processingTime) {
            $metadataText = [];
            if ($provider) {
                $metadataText[] = 'Service: '.$provider;
            }
            if ($model) {
                $metadataText[] = 'Model: '.$model;
            }
            if (null !== $processingTime) {
                $metadataText[] = sprintf('Processing time: %.2f seconds', $processingTime);
            }
            if (!empty($metadataText)) {
                $textBody .= "\n\n---\n".implode(' | ', $metadataText);
                $textBody .= "\nhttps://www.synaplan.com/";
            }
        }

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($to)
            ->subject('Re: '.$subject)
            ->text($textBody)
            ->html($htmlBody);

        // Add In-Reply-To header for email threading
        if ($inReplyTo) {
            $email->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
            $email->getHeaders()->addTextHeader('References', $inReplyTo);
        }

        try {
            $this->mailer->send($email);
            $this->logger->info('AI response email sent', [
                'to' => $to,
                'subject' => $subject,
                'provider' => $provider,
                'model' => $model,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send AI response email', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Render email template with locale support
     * Translates all strings before passing to template.
     */
    private function renderTemplate(string $template, array $context, string $locale): string
    {
        // Pre-translate all email strings based on template type
        $translations = [];

        if (str_contains($template, 'verification')) {
            $translations = [
                'title' => $this->translator->trans('email.verification.title', [], 'emails', $locale),
                'intro' => $this->translator->trans('email.verification.intro', [], 'emails', $locale),
                'instruction' => $this->translator->trans('email.verification.instruction', [], 'emails', $locale),
                'button' => $this->translator->trans('email.verification.button', [], 'emails', $locale),
                'or_copy' => $this->translator->trans('email.verification.or_copy', [], 'emails', $locale),
                'expiry' => $this->translator->trans('email.verification.expiry', [], 'emails', $locale),
                'footer_notice' => $this->translator->trans('email.verification.footer_notice', [], 'emails', $locale),
            ];
        } elseif (str_contains($template, 'password-reset')) {
            $translations = [
                'title' => $this->translator->trans('email.password_reset.title', [], 'emails', $locale),
                'intro' => $this->translator->trans('email.password_reset.intro', [], 'emails', $locale),
                'instruction' => $this->translator->trans('email.password_reset.instruction', [], 'emails', $locale),
                'button' => $this->translator->trans('email.password_reset.button', [], 'emails', $locale),
                'or_copy' => $this->translator->trans('email.password_reset.or_copy', [], 'emails', $locale),
                'security_notice_title' => $this->translator->trans('email.password_reset.security_notice.title', [], 'emails', $locale),
                'security_notice_text' => $this->translator->trans('email.password_reset.security_notice.text', [], 'emails', $locale),
                'footer_support' => $this->translator->trans('email.footer.support', [], 'emails', $locale),
            ];
        } elseif (str_contains($template, 'welcome')) {
            $translations = [
                'title' => $this->translator->trans('email.welcome.title', [], 'emails', $locale),
                'greeting' => $this->translator->trans('email.welcome.greeting', ['%name%' => $context['name'] ?? ''], 'emails', $locale),
                'intro' => $this->translator->trans('email.welcome.intro', [], 'emails', $locale),
                'features_intro' => $this->translator->trans('email.welcome.features_intro', [], 'emails', $locale),
                'feature_1' => $this->translator->trans('email.welcome.feature_1', [], 'emails', $locale),
                'feature_2' => $this->translator->trans('email.welcome.feature_2', [], 'emails', $locale),
                'feature_3' => $this->translator->trans('email.welcome.feature_3', [], 'emails', $locale),
                'feature_4' => $this->translator->trans('email.welcome.feature_4', [], 'emails', $locale),
                'button' => $this->translator->trans('email.welcome.button', [], 'emails', $locale),
                'footer_help' => $this->translator->trans('email.footer.help', [], 'emails', $locale),
            ];
        }

        // Add common footer translations
        $translations['footer_company'] = $this->translator->trans('email.footer.company', [], 'emails', $locale);
        $translations['footer_rights'] = $this->translator->trans('email.footer.rights', [], 'emails', $locale);

        // Merge translations into context
        return $this->twig->render($template, array_merge($context, ['t' => $translations]));
    }
}
