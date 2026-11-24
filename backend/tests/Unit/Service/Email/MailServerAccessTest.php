<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Entity\InboundEmailHandler;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Email\MailServerAccess;
use App\Service\Email\MailServerRejected;
use App\Service\Security\EncryptionService;
use App\Service\Security\HostResolver;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every case here is refused before a socket opens: the resolver is a stub
 * and no test names a target the guard accepts.
 */
final class MailServerAccessTest extends TestCase
{
    private const OWNER_ID = 5;

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function refusedTargets(): iterable
    {
        yield 'compose service name' => ['qdrant', 993];
        yield 'loopback' => ['127.0.0.1', 993];
        yield 'localhost' => ['localhost', 993];
        yield 'cloud metadata' => ['169.254.169.254', 993];
        yield 'private network' => ['10.0.0.5', 993];
        yield 'name resolving to a private address' => ['intranet.example', 993];
        yield 'mailbox flag injection' => ['mail.example}/x', 993];
        yield 'flag after a slash' => ['mail.example/novalidate-cert', 993];
        yield 'bracketed address' => ['[::1]', 993];
        yield 'empty' => ['', 993];
        yield 'public name, port that is not a mail port' => ['public.example', 6379];
    }

    #[DataProvider('refusedTargets')]
    public function testAMailboxIsRefusedBeforeItIsContacted(string $host, int $port): void
    {
        $this->expectException(MailServerRejected::class);

        $this->access()->open($host, $port, 'IMAP', 'SSL/TLS', 'user@example.com', 'secret');
    }

    public function testAnUnknownProtocolIsRefused(): void
    {
        $this->expectExceptionObject(MailServerRejected::protocol());

        $this->access()->open('public.example', 993, 'IMAP/novalidate-cert', 'SSL/TLS', 'user@example.com', 'secret');
    }

    public function testAnAdministratorStillCannotReachTheMetadataAddress(): void
    {
        $this->expectExceptionObject(MailServerRejected::address());

        $this->access()->open('169.254.169.254', 993, 'IMAP', 'SSL/TLS', 'user@example.com', 'secret', operatorNetwork: true);
    }

    public function testSmtpSettingsOfAnInternalServerAreRefused(): void
    {
        $handler = $this->handlerWithSmtp('10.0.0.5', 587);

        $this->expectExceptionObject(MailServerRejected::address());

        $this->access()->smtpCredentials($handler);
    }

    public function testSmtpSettingsOfAPublicServerAreReturned(): void
    {
        $smtp = $this->access()->smtpCredentials($this->handlerWithSmtp('public.example', 587));

        self::assertNotNull($smtp);
        self::assertSame('public.example', $smtp['server']);
        self::assertSame(587, $smtp['port']);
    }

    public function testAnAdministratorMayForwardThroughAServerOnTheOperatorNetwork(): void
    {
        $smtp = $this->access(ownerIsAdmin: true)->smtpCredentials($this->handlerWithSmtp('10.0.0.5', 1025));

        self::assertNotNull($smtp);
        self::assertSame('10.0.0.5', $smtp['server']);
    }

    public function testAHandlerWithoutSmtpSettingsHasNone(): void
    {
        self::assertNull($this->access()->smtpCredentials(new InboundEmailHandler()));
    }

    public function testTheMailboxSpecificationCarriesOnlyTheKnownFlags(): void
    {
        $mailbox = new \ReflectionMethod(MailServerAccess::class, 'mailbox');

        self::assertSame('{imap.example.com:993/imap/ssl}INBOX', $mailbox->invoke(null, 'imap.example.com', 993, 'imap', 'SSL/TLS', false));
        self::assertSame('{pop3.example.com:995/pop3/ssl}INBOX', $mailbox->invoke(null, 'pop3.example.com', 995, 'pop3', 'SSL/TLS', false));
        self::assertSame('{imap.example.com:143/imap/tls}INBOX', $mailbox->invoke(null, 'imap.example.com', 143, 'imap', 'STARTTLS', false));
        self::assertSame('{imap.example.com:143/imap/notls/readonly}INBOX', $mailbox->invoke(null, 'imap.example.com', 143, 'imap', 'anything else', true));
    }

    private function access(bool $ownerIsAdmin = false): MailServerAccess
    {
        $resolver = new class implements HostResolver {
            public function addresses(string $host): array
            {
                return match ($host) {
                    'public.example' => ['93.184.216.34'],
                    'intranet.example' => ['192.168.1.20'],
                    default => [],
                };
            }
        };

        $owner = $this->createStub(User::class);
        $owner->method('isAdmin')->willReturn($ownerIsAdmin);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($owner);

        $encryption = $this->createStub(EncryptionService::class);
        $encryption->method('decrypt')->willReturn('secret');

        return new MailServerAccess(new SsrfGuard($resolver), $encryption, $users, new NullLogger());
    }

    private function handlerWithSmtp(string $server, int $port): InboundEmailHandler
    {
        $handler = new InboundEmailHandler();
        $handler->setUserId(self::OWNER_ID);
        $handler->setConfig(['smtp' => [
            'server' => $server,
            'port' => $port,
            'username' => 'user@example.com',
            'password' => 'encrypted',
            'security' => 'STARTTLS',
        ]]);

        return $handler;
    }
}
