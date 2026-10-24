<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Service\Auth\SignupAnomalyAlert;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class SignupAnomalyAlertTest extends TestCase
{
    private CollectingMailer $mailer;

    protected function setUp(): void
    {
        $this->mailer = new CollectingMailer();
    }

    /** @return list<Email> */
    private function sentMails(): array
    {
        return $this->mailer->sent;
    }

    public function testSignupBurstMailsTheOperatorOncePerHour(): void
    {
        $alert = $this->alert(paidLimit: 100);

        $alert->signupBurst(['last_ip' => '203.0.113.1']);
        $alert->signupBurst(['last_ip' => '203.0.113.2']);

        $this->assertCount(1, $this->sentMails(), 'Throttled: one mail per hour per signal');
        $mail = $this->sentMails()[0];
        $this->assertSame('ops@example.com', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('[INCIDENT]', (string) $mail->getSubject());
        $this->assertStringContainsString('last_ip: 203.0.113.1', (string) $mail->getTextBody());
        $this->assertStringContainsString('Pause sign-up', (string) $mail->getTextBody());
    }

    public function testPaidTierGrantsToFreshAccountsAlertAboveTheLimit(): void
    {
        $alert = $this->alert(paidLimit: 2);
        $fresh = $this->user(date('Y-m-d H:i:s'));

        $alert->paidTierGranted($fresh, 'BUSINESS');
        $alert->paidTierGranted($fresh, 'BUSINESS');
        $this->assertCount(0, $this->sentMails(), 'Within the limit nothing is sent');

        $alert->paidTierGranted($fresh, 'BUSINESS');
        $this->assertCount(1, $this->sentMails());
        $this->assertStringContainsString('paid_signup_burst', (string) $this->sentMails()[0]->getSubject());
    }

    public function testOldAccountsUpgradingAreNotASignal(): void
    {
        $alert = $this->alert(paidLimit: 1);
        $old = $this->user(date('Y-m-d H:i:s', time() - 30 * 86400));

        $alert->paidTierGranted($old, 'PRO');
        $alert->paidTierGranted($old, 'PRO');
        $alert->paidTierGranted($old, 'PRO');

        $this->assertCount(0, $this->sentMails());
    }

    public function testWithoutAnOperatorInboxItOnlyLogs(): void
    {
        $alert = new SignupAnomalyAlert($this->limiter(100), $this->mailer, new ArrayAdapter(), new NullLogger(), '', '');

        $alert->signupBurst();

        $this->assertCount(0, $this->sentMails());
    }

    private function alert(int $paidLimit): SignupAnomalyAlert
    {
        return new SignupAnomalyAlert($this->limiter($paidLimit), $this->mailer, new ArrayAdapter(), new NullLogger(), 'ops@example.com', 'noreply@example.com');
    }

    private function limiter(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'paid_signup_alert', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage());
    }

    private function user(string $created): User
    {
        $user = new User();
        $user->setMail('alert@test.synaplan.com');
        $user->setCreated($created);

        return $user;
    }
}

final class CollectingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
    {
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }
    }
}
