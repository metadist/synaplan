<?php

namespace App\Service;

use Parsedown;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
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
final readonly class InternalEmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Send with ONE retry on transient transport failures.
     *
     * In long-running processes (FrankenPHP workers, messenger consumers) the
     * SMTP connection is kept open between sends. AWS SES closes connections
     * idle for ~10s — a hard limit below Symfony's default 100s ping threshold
     * — so the next send on a reused connection fails with
     * "451 4.4.2 Timeout waiting for data from client." even though nothing is
     * wrong with the message. A failed attempt resets the transport's idle
     * clock, which makes the retry NOOP-ping first, detect the dead socket and
     * reconnect — so a single retry reliably recovers.
     *
     * Permanent SMTP rejections (5xx) are NOT retried.
     */
    private function sendWithRetry(Email $email): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            if ($e instanceof UnexpectedResponseException && $e->getCode() >= 500) {
                throw $e;
            }

            $this->logger->warning('Transient mail transport failure, retrying once on a fresh connection', [
                'error' => $e->getMessage(),
            ]);

            $this->mailer->send($email);
        }
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
            $this->sendWithRetry($email);
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
            $this->sendWithRetry($email);
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
            $this->sendWithRetry($email);
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
     * @param string      $to                Recipient email address
     * @param string      $subject           Original email subject
     * @param string      $bodyText          AI response text (markdown format)
     * @param string|null $inReplyTo         Message ID for email threading
     * @param string|null $provider          AI provider name (e.g., 'ollama', 'openai')
     * @param string|null $model             AI model name (e.g., 'llama3.2')
     * @param float|null  $processingTime    Processing time in seconds
     * @param string|null $attachmentPath    Path to attachment file
     * @param string|null $originalRecipient The address the user originally wrote to (used as From/Reply-To)
     * @param string|null $mediaType         Type of media attachment ('image', 'video', 'audio') for inline embedding
     */
    public function sendAiResponseEmail(
        string $to,
        string $subject,
        string $bodyText,
        ?string $inReplyTo = null,
        ?string $provider = null,
        ?string $model = null,
        ?float $processingTime = null,
        ?string $attachmentPath = null,
        ?string $originalRecipient = null,
        ?string $mediaType = null,
        ?array $additionalAttachmentPaths = null,
    ): void {
        $fallbackAddress = $_ENV['SMART_EMAIL_ADDRESS'] ?? \App\Service\Email\SmartEmailHelper::getBaseAddress();
        $smartAddress = ($originalRecipient && \App\Service\Email\SmartEmailHelper::isValidSmartAddress($originalRecipient))
            ? $originalRecipient
            : $fallbackAddress;
        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'smart@synaplan.net';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan AI';
        $replyToEmail = $smartAddress;

        $hasInlineImage = false;

        // Convert markdown to HTML using Parsedown
        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(true); // Prevent XSS
        $htmlBody = $parsedown->text($bodyText);

        // Embed images inline via CID for broad email client compatibility (Outlook, Gmail, etc.)
        if ('image' === $mediaType && $attachmentPath && file_exists($attachmentPath)) {
            $htmlBody .= '<br><br><img src="cid:generated-image" alt="Generated image" style="max-width: 100%; border-radius: 8px;">';
            $hasInlineImage = true;
        }

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
            ->replyTo($replyToEmail)
            ->to($to)
            ->subject('Re: '.$subject)
            ->text($textBody)
            ->html($htmlBody);

        // Add In-Reply-To header for email threading
        if ($inReplyTo) {
            $email->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
            $email->getHeaders()->addTextHeader('References', $inReplyTo);
        }

        if ($attachmentPath && file_exists($attachmentPath)) {
            if ($hasInlineImage) {
                $email->embedFromPath($attachmentPath, 'generated-image');
            } else {
                $email->attachFromPath($attachmentPath);
            }
        }

        // Multi-task routing (Sprint 5): attach any additional output files
        // beyond the primary one. Single-file turns pass null here, so behaviour
        // is unchanged.
        foreach ($additionalAttachmentPaths ?? [] as $extraPath) {
            if (is_string($extraPath) && '' !== $extraPath && file_exists($extraPath)) {
                $email->attachFromPath($extraPath);
            }
        }

        try {
            $this->sendWithRetry($email);
            $this->logger->info('AI response email sent', [
                'to' => $to,
                'subject' => $subject,
                'provider' => $provider,
                'model' => $model,
                'has_attachment' => (bool) $attachmentPath,
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
     * Send a multi-task ("email_me" DAG node) result email to the account owner.
     *
     * Mirrors {@see sendAiResponseEmail()}: markdown body → HTML via Parsedown,
     * the FIRST image attachment is embedded inline (CID) for broad client
     * compatibility, every other file is attached as a regular MIME part.
     *
     * @param string                                        $to          Recipient (account owner) address
     * @param string                                        $subject     Localized subject (caller resolves the locale)
     * @param string                                        $markdown    Result text in markdown
     * @param list<array{path: string, type?: string|null}> $attachments Absolute file paths (+ optional media kind)
     */
    public function sendTaskResultEmail(string $to, string $subject, string $markdown, array $attachments = []): void
    {
        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'noreply@synaplan.com';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan';

        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(true); // Prevent XSS
        $htmlBody = $parsedown->text($markdown);

        // Split attachments: first image becomes the inline (CID) hero image.
        $inlineImagePath = null;
        $regularPaths = [];
        foreach ($attachments as $attachment) {
            $path = $attachment['path'];
            if ('' === $path || !file_exists($path)) {
                continue;
            }
            if (null === $inlineImagePath && $this->isImageAttachment($path, $attachment['type'] ?? null)) {
                $inlineImagePath = $path;
            } else {
                $regularPaths[] = $path;
            }
        }

        if (null !== $inlineImagePath) {
            $htmlBody .= '<br><br><img src="cid:generated-image" alt="Generated image" style="max-width: 100%; border-radius: 8px;">';
        }

        $htmlBody .= '<br><br><div style="font-size: 11px; color: #888888; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e0e0e0;">'
            .'<a href="https://www.synaplan.com/" style="color: #888888;">www.synaplan.com</a></div>';

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($to)
            ->subject($subject)
            ->text($markdown)
            ->html($htmlBody);

        if (null !== $inlineImagePath) {
            $email->embedFromPath($inlineImagePath, 'generated-image');
        }
        foreach ($regularPaths as $path) {
            $email->attachFromPath($path);
        }

        try {
            $this->sendWithRetry($email);
            $this->logger->info('Task result email sent', [
                'to' => $to,
                'attachment_count' => count($attachments),
                'has_inline_image' => null !== $inlineImagePath,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send task result email', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Whether an attachment should be treated as an embeddable image — by
     * descriptor type first, file extension as fallback.
     */
    private function isImageAttachment(string $path, ?string $type): bool
    {
        if (is_string($type) && 'image' === strtolower($type)) {
            return true;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    }

    /**
     * Send a warning email when the embedding fallback provider activates.
     */
    public function sendEmbeddingFallbackWarning(string $primaryProvider, string $fallbackProvider, string $errorMessage): void
    {
        $adminEmail = $_ENV['APP_ADMIN_EMAIL'] ?? $_ENV['APP_SENDER_EMAIL'] ?? null;
        if (!$adminEmail) {
            $this->logger->debug('No admin email configured, skipping fallback warning');

            return;
        }

        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'noreply@synaplan.com';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan';
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s T');

        $html = <<<HTML
            <div style="font-family: -apple-system, sans-serif; max-width: 600px; margin: 0 auto;">
                <div style="background: #c62828; color: #fff; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px;">
                    <strong style="font-size: 18px;">🚨 INCIDENT — Embedding Fallback Activated</strong>
                </div>
                <p>The primary embedding provider <strong>{$primaryProvider}</strong> failed.
                   Requests are now routed to the fallback provider <strong>{$fallbackProvider}</strong>.</p>
                <p><strong>Action required:</strong> verify the primary provider's health and capacity.
                   Every embedding request hitting the fallback consumes paid quota.</p>
                <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
                    <tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Primary (DOWN)</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{$primaryProvider}</td></tr>
                    <tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Fallback (active)</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{$fallbackProvider}</td></tr>
                    <tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Error</td>
                        <td style="padding: 8px; border: 1px solid #ddd; color: #c62828; font-family: monospace;">{$errorMessage}</td></tr>
                    <tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Time</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{$timestamp}</td></tr>
                </table>
                <p style="color: #666; font-size: 13px;">Throttled to one alert per hour per provider pair.
                   Discord channel was notified with @everyone in parallel.</p>
            </div>
            HTML;

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($adminEmail)
            // Subject prefix is intentionally [INCIDENT] so inbox rules
            // / pagers can match on it without parsing the body.
            ->subject('[INCIDENT][Synaplan] Embedding Fallback Activated — '.$primaryProvider.' → '.$fallbackProvider)
            ->priority(Email::PRIORITY_HIGH)
            ->html($html);

        try {
            $this->sendWithRetry($email);
            $this->logger->info('Embedding fallback warning email sent', ['to' => $adminEmail]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send embedding fallback warning email', [
                'to' => $adminEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the operator inbox about a new content report (Apple Guideline 1.2).
     *
     * Sent to APP_ADMIN_EMAIL, falling back to the sender address and finally the
     * fixed abuse contact so a report is never silently dropped. Best-effort: a
     * transport failure is logged, never surfaced to the reporting user.
     *
     * @param array{id:int,contentType:string,contentId:int,reason:string,details:?string,reporterId:int,reporterEmail:?string,reportedUserId:?int,reportedUserEmail:?string,created:string} $report
     */
    public function sendModerationReportEmail(array $report): void
    {
        $adminEmail = $_ENV['APP_ADMIN_EMAIL'] ?? $_ENV['APP_SENDER_EMAIL'] ?? 'team@synaplan.com';
        $fromEmail = $_ENV['APP_SENDER_EMAIL'] ?? 'noreply@synaplan.com';
        $fromName = $_ENV['APP_SENDER_NAME'] ?? 'Synaplan';

        $reason = htmlspecialchars($report['reason'], ENT_QUOTES);
        $details = null !== $report['details'] && '' !== $report['details']
            ? nl2br(htmlspecialchars($report['details'], ENT_QUOTES))
            : '<em>(none)</em>';
        $reporter = htmlspecialchars(($report['reporterEmail'] ?? '').' (#'.$report['reporterId'].')', ENT_QUOTES);
        $reportedUser = null !== $report['reportedUserId']
            ? htmlspecialchars(($report['reportedUserEmail'] ?? '').' (#'.$report['reportedUserId'].')', ENT_QUOTES)
            : '<em>(unresolved)</em>';
        $contentType = htmlspecialchars($report['contentType'], ENT_QUOTES);
        $contentId = (int) $report['contentId'];
        $created = htmlspecialchars($report['created'], ENT_QUOTES);

        $html = <<<HTML
            <div style="font-family: -apple-system, sans-serif; max-width: 600px; margin: 0 auto;">
                <div style="background: #c62828; color: #fff; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px;">
                    <strong style="font-size: 18px;">Content report #{$report['id']}</strong>
                </div>
                <p>A user reported potentially objectionable content. Please review within 24 hours
                   and, if warranted, suspend the offending account in the admin moderation panel.</p>
                <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
                    <tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Reason</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{$reason}</td></tr>
                    <tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Content</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{$contentType} #{$contentId}</td></tr>
                    <tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Reported user</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{$reportedUser}</td></tr>
                    <tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Reporter</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{$reporter}</td></tr>
                    <tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Details</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{$details}</td></tr>
                    <tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">Time</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{$created}</td></tr>
                </table>
            </div>
            HTML;

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($adminEmail)
            ->subject('[MODERATION][Synaplan] Content report #'.$report['id'].' — '.$report['reason'])
            ->priority(Email::PRIORITY_HIGH)
            ->html($html);

        try {
            $this->sendWithRetry($email);
            $this->logger->info('Moderation report email sent', ['to' => $adminEmail, 'report_id' => $report['id']]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send moderation report email', [
                'to' => $adminEmail,
                'error' => $e->getMessage(),
            ]);
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
