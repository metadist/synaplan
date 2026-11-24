<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\InboundEmailHandler;
use App\Repository\UserRepository;
use App\Service\Security\EncryptionService;
use App\Service\Security\SsrfGuard;
use Psr\Log\LoggerInterface;

/**
 * The one place a handler's mail servers are contacted.
 *
 * Server, port and credentials of a handler are typed by its owner, so a
 * connection is a request the server makes on that person's behalf. Before
 * any socket opens, the host has to be a plain host name or address that
 * {@see SsrfGuard} accepts, and the port a mail port. An administrator runs
 * the installation and may name a server on its own network and any port.
 *
 * What the mail library reports goes to the log. The caller gets one of the
 * fixed sentences of {@see MailServerRejected}.
 */
final readonly class MailServerAccess
{
    /** @var list<int> */
    private const MAILBOX_PORTS = [110, 143, 993, 995];

    /** @var list<int> */
    private const SMTP_PORTS = [25, 465, 587, 2525];

    private const PROTOCOLS = ['imap', 'pop3'];

    /** A host name or IPv4 address; nothing the mailbox syntax could read as a flag. */
    private const HOST_PATTERN = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i';

    private const SIGN_IN_FAILURE = '/authenticat|login|credential|password/i';

    private const OPEN_TIMEOUT_SECONDS = 10;
    private const IO_TIMEOUT_SECONDS = 20;

    public function __construct(
        private SsrfGuard $ssrfGuard,
        private EncryptionService $encryptionService,
        private UserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws MailServerRejected
     */
    public function openHandler(InboundEmailHandler $handler, bool $readOnly = false): \IMAP\Connection
    {
        return $this->open(
            $handler->getMailServer(),
            $handler->getPort(),
            $handler->getProtocol(),
            $handler->getSecurity(),
            $handler->getUsername(),
            $handler->getDecryptedPassword($this->encryptionService),
            $this->ownerRunsTheInstallation($handler),
            $readOnly,
        );
    }

    /**
     * @param bool $operatorNetwork true only when an administrator named the server
     *
     * @throws MailServerRejected
     */
    public function open(
        string $mailServer,
        int $port,
        string $protocol,
        string $security,
        string $username,
        string $plainPassword,
        bool $operatorNetwork = false,
        bool $readOnly = false,
    ): \IMAP\Connection {
        $mailServer = trim($mailServer);
        $protocol = strtolower($protocol);
        if (!in_array($protocol, self::PROTOCOLS, true)) {
            throw MailServerRejected::protocol();
        }
        $this->assertTarget($mailServer, $port, self::MAILBOX_PORTS, $operatorNetwork);

        if (!function_exists('imap_open')) {
            $this->logger->error('IMAP extension not available');
            throw MailServerRejected::connection();
        }

        imap_timeout(\IMAP_OPENTIMEOUT, self::OPEN_TIMEOUT_SECONDS);
        imap_timeout(\IMAP_READTIMEOUT, self::IO_TIMEOUT_SECONDS);
        imap_timeout(\IMAP_WRITETIMEOUT, self::IO_TIMEOUT_SECONDS);

        $connection = @imap_open(
            self::mailbox($mailServer, $port, $protocol, $security, $readOnly),
            $username,
            $plainPassword,
            $readOnly ? \OP_READONLY : 0,
        );
        if (false !== $connection) {
            return $connection;
        }

        $errors = implode(', ', imap_errors() ?: ['unknown error']);
        $this->logger->warning('Mailbox connection failed', [
            'server' => $mailServer,
            'port' => $port,
            'errors' => $errors,
            'alerts' => imap_alerts() ?: [],
        ]);

        throw 1 === preg_match(self::SIGN_IN_FAILURE, $errors) ? MailServerRejected::signIn() : MailServerRejected::connection();
    }

    /**
     * The decrypted outbound settings of a handler, or null when it has none.
     *
     * @return array{server: string, port: int, username: string, password: string, security: string}|null
     *
     * @throws MailServerRejected when the SMTP server is not an allowed target
     */
    public function smtpCredentials(InboundEmailHandler $handler): ?array
    {
        $smtp = $handler->getSmtpCredentials($this->encryptionService);
        if (null === $smtp) {
            return null;
        }

        $smtp['server'] = trim((string) $smtp['server']);
        $smtp['port'] = (int) $smtp['port'];
        $this->assertTarget($smtp['server'], $smtp['port'], self::SMTP_PORTS, $this->ownerRunsTheInstallation($handler));

        return $smtp;
    }

    /**
     * @param list<int> $ports
     *
     * @throws MailServerRejected
     */
    private function assertTarget(string $host, int $port, array $ports, bool $operatorNetwork): void
    {
        if (1 !== preg_match(self::HOST_PATTERN, $host)) {
            throw MailServerRejected::address();
        }

        if ($operatorNetwork ? ($port < 1 || $port > 65535) : !in_array($port, $ports, true)) {
            throw MailServerRejected::port($ports);
        }

        if ($this->ssrfGuard->isBlockedHost($host, $operatorNetwork)) {
            $this->logger->warning('Mail server target refused', ['server' => $host, 'port' => $port]);
            throw MailServerRejected::address();
        }
    }

    private function ownerRunsTheInstallation(InboundEmailHandler $handler): bool
    {
        return true === $this->users->find($handler->getUserId())?->isAdmin();
    }

    private static function mailbox(string $host, int $port, string $protocol, string $security, bool $readOnly): string
    {
        $securityFlag = match ($security) {
            'SSL/TLS' => 'ssl',
            'STARTTLS' => 'tls',
            default => 'notls',
        };

        return sprintf('{%s:%d/%s/%s%s}INBOX', $host, $port, $protocol, $securityFlag, $readOnly ? '/readonly' : '');
    }
}
