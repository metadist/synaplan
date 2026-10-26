<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Sends the one-time sign-in link (PF3).
 */
final readonly class LoginLinkMailer
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

    public function send(string $to, string $secret, string $locale): void
    {
        $url = sprintf('%s/login/link?token=%s', rtrim($this->frontendUrl, '/'), $secret);
        $t = fn (string $key): string => $this->translator->trans('email.login_link.'.$key, [], 'emails', $locale);
        $from = '' !== trim((string) $this->senderEmail) ? trim((string) $this->senderEmail) : 'noreply@synaplan.com';
        $name = '' !== trim((string) $this->senderName) ? trim((string) $this->senderName) : 'Synaplan';

        $email = (new Email())
            ->from(sprintf('%s <%s>', $name, $from))
            ->to($to)
            ->subject($t('title'))
            ->html($this->twig->render('emails/login-link.html.twig', [
                'url' => $url,
                't' => [
                    'title' => $t('title'),
                    'body' => $t('body'),
                    'button' => $t('button'),
                    'or_copy' => $t('or_copy'),
                    'not_you' => $t('not_you'),
                    'footer_company' => $this->translator->trans('email.footer.company', [], 'emails', $locale),
                    'footer_rights' => $this->translator->trans('email.footer.rights', [], 'emails', $locale),
                ],
            ]));

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Sign-in link mail could not be sent', ['error' => $e->getMessage()]);
        }
    }
}
