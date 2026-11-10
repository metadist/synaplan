<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells the person that a cloud can now act for them, and tells the previous
 * owner when a cloud account moves to someone else. A mail that cannot be
 * sent does not undo the link.
 */
final readonly class CloudGrantMailer
{
    public function __construct(
        private UserRepository $users,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire(env: 'default::APP_SENDER_EMAIL')]
        private ?string $senderEmail = null,
        #[Autowire(env: 'default::APP_SENDER_NAME')]
        private ?string $senderName = null,
    ) {
    }

    public function notify(int $userId, PlatformInstance $instance, string $externalId, bool $lost): void
    {
        $user = $this->users->find($userId);
        $to = $user?->getMail();
        if (!$user instanceof User || null === $to || '' === $to) {
            return;
        }
        $kind = $lost ? 'lost' : 'connected';
        $params = [
            '%client%' => $instance->getClient(),
            '%host%' => $instance->getHost(),
            '%external_id%' => $externalId,
        ];
        $locale = $user->getLocale();
        $from = '' !== trim((string) $this->senderEmail) ? trim((string) $this->senderEmail) : 'noreply@synaplan.com';
        $name = '' !== trim((string) $this->senderName) ? trim((string) $this->senderName) : 'Synaplan';
        $email = (new Email())
            ->from(sprintf('%s <%s>', $name, $from))
            ->to($to)
            ->subject($this->translator->trans('email.cloud_grant.'.$kind.'.subject', $params, 'emails', $locale))
            ->text($this->translator->trans('email.cloud_grant.'.$kind.'.body', $params, 'emails', $locale));

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->warning('The cloud connection mail could not be sent', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
