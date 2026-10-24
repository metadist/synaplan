<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Tells the operator when account creation leaves its normal band.
 *
 * Two signals:
 *  - the installation-wide signup limiter tripped ({@see SignupGuard}), and
 *  - more fresh accounts (younger than a day) received a paid tier within an
 *    hour than `PAID_SIGNUP_ALERT_LIMIT` allows ({@see \App\Service\Billing\StripeEntitlement}).
 *
 * Each signal mails the operator inbox (`APP_ADMIN_EMAIL`, falling back to
 * `APP_SENDER_EMAIL`) at most once per hour and always logs at `critical`, so
 * the alert reaches a pager rule even on an install without mail. The
 * 2026-10-24 burst peaked at 30 new paid accounts per minute and was noticed
 * by a person, not a monitor — this is the monitor.
 */
final readonly class SignupAnomalyAlert
{
    private const THROTTLE_SECONDS = 3600;
    private const FRESH_ACCOUNT_SECONDS = 86400;

    public function __construct(
        private RateLimiterFactoryInterface $paidSignupAlertLimiter,
        private MailerInterface $mailer,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private string $adminEmail,
        private string $senderEmail,
    ) {
    }

    /**
     * The installation-wide signup limiter refused a request.
     *
     * @param array<string, scalar|null> $context
     */
    public function signupBurst(array $context = []): void
    {
        $this->raise('signup_burst', 'Account creation is above the configured installation-wide limit and is being refused.', $context);
    }

    /**
     * A paid tier was granted. Counts only accounts younger than a day — a
     * long-standing customer upgrading is not a signal.
     */
    public function paidTierGranted(User $user, string $level): void
    {
        $created = strtotime($user->getCreated());
        if (false === $created || time() - $created > self::FRESH_ACCOUNT_SECONDS) {
            return;
        }

        if ($this->paidSignupAlertLimiter->create('fresh-accounts')->consume()->isAccepted()) {
            return;
        }

        $this->raise('paid_signup_burst', 'More brand-new accounts received a paid tier in the last hour than the configured limit allows.', [
            'last_user_id' => $user->getId(),
            'last_level' => $level,
        ]);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function raise(string $kind, string $summary, array $context): void
    {
        $this->logger->critical('Signup anomaly: '.$summary, ['kind' => $kind] + $context);

        $item = $this->cache->getItem('signup_anomaly_alert_'.$kind);
        if ($item->isHit()) {
            return;
        }
        $item->set(time());
        $item->expiresAfter(self::THROTTLE_SECONDS);
        $this->cache->save($item);

        $to = '' !== trim($this->adminEmail) ? trim($this->adminEmail) : trim($this->senderEmail);
        if ('' === $to) {
            $this->logger->warning('Signup anomaly alert has no operator inbox (APP_ADMIN_EMAIL / APP_SENDER_EMAIL unset)');

            return;
        }

        $lines = [];
        foreach ($context as $key => $value) {
            $lines[] = sprintf('%s: %s', $key, null === $value ? '-' : (string) $value);
        }
        $body = $summary."\n\n"
            .implode("\n", $lines)
            ."\n\nTime: ".(new \DateTimeImmutable())->format('Y-m-d H:i:s T')
            ."\n\nWhat to check: Admin → Users (newest accounts), Stripe → Payments (payment method and status), Cloudflare → Security events."
            ."\nWhat to do: pause sign-up (Admin → Configuration → Authentication → Pause sign-up) until the source is understood."
            ."\n\nThrottled to one mail per hour per signal.";

        $email = (new Email())
            ->from(sprintf('Synaplan <%s>', '' !== trim($this->senderEmail) ? trim($this->senderEmail) : $to))
            ->to($to)
            ->subject('[INCIDENT][Synaplan] Unusual account creation — '.$kind)
            ->priority(Email::PRIORITY_HIGH)
            ->text($body);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->warning('Signup anomaly alert mail failed', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }
}
