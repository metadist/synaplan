<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The one mail a pay-first buyer gets from us after paying: either "your
 * account is ready, set a password" or "your plan is on the account you
 * already had". Stripe sends the receipt itself.
 */
final readonly class PaidAccountMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire(env: 'FRONTEND_URL')]
        private string $frontendUrl,
        #[Autowire(env: 'default::APP_SENDER_EMAIL')]
        private ?string $senderEmail = null,
        #[Autowire(env: 'default::APP_SENDER_NAME')]
        private ?string $senderName = null,
    ) {
    }

    public function accountReady(string $to, string $plan, string $passwordToken, string $locale): void
    {
        $this->send($to, 'ready', $plan, sprintf('%s/reset-password?token=%s', rtrim($this->frontendUrl, '/'), $passwordToken), $locale);
    }

    public function planOnExistingAccount(string $to, string $plan, string $locale): void
    {
        $this->send($to, 'existing', $plan, rtrim($this->frontendUrl, '/').'/login', $locale);
    }

    private function send(string $to, string $kind, string $plan, string $url, string $locale): void
    {
        $planName = ucfirst(strtolower($plan));
        $t = fn (string $key): string => $this->translator->trans('email.paid_account.'.$key, ['%plan%' => $planName], 'emails', $locale);
        $from = '' !== trim((string) $this->senderEmail) ? trim((string) $this->senderEmail) : 'noreply@synaplan.com';
        $name = '' !== trim((string) $this->senderName) ? trim((string) $this->senderName) : 'Synaplan';

        $email = (new Email())
            ->from(sprintf('%s <%s>', $name, $from))
            ->to($to)
            ->subject($t($kind.'.title'))
            ->html($this->twig->render('emails/paid-account.html.twig', [
                'url' => $url,
                't' => [
                    'title' => $t($kind.'.title'),
                    'body' => $t($kind.'.body'),
                    'button' => $t($kind.'.button'),
                    'or_copy' => $t('or_copy'),
                    'footer_company' => $this->translator->trans('email.footer.company', [], 'emails', $locale),
                    'footer_rights' => $this->translator->trans('email.footer.rights', [], 'emails', $locale),
                ],
            ]));

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            // The account and the plan exist; the sign-in page and "forgot
            // password" still reach them without this mail.
            $this->logger->error('Pay-first account mail could not be sent', ['kind' => $kind, 'error' => $e->getMessage()]);
        }
    }
}
