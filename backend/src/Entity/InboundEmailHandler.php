<?php

namespace App\Entity;

use App\Repository\InboundEmailHandlerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Inbound Email Handler Entity.
 *
 * Stores IMAP/POP3 configuration for email routing tool.
 * Each handler belongs to a user and contains departments for AI-based routing.
 */
#[ORM\Entity(repositoryClass: InboundEmailHandlerRepository::class)]
#[ORM\Table(name: 'BINBOUNDEMAILHANDLER')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_user')]
#[ORM\Index(columns: ['BSTATUS'], name: 'idx_status')]
class InboundEmailHandler
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BNAME', length: 255)]
    private string $name = '';

    // IMAP/POP3 Connection Config
    #[ORM\Column(name: 'BMAILSERVER', length: 255)]
    private string $mailServer = '';

    #[ORM\Column(name: 'BPORT', type: 'integer')]
    private int $port = 993;

    #[ORM\Column(name: 'BPROTOCOL', length: 10)]
    private string $protocol = 'IMAP'; // IMAP or POP3

    #[ORM\Column(name: 'BSECURITY', length: 20)]
    private string $security = 'SSL/TLS'; // SSL/TLS, STARTTLS, None

    #[ORM\Column(name: 'BUSERNAME', length: 255)]
    private string $username = '';

    #[ORM\Column(name: 'BPASSWORD', type: 'text')]
    private string $password = ''; // Encrypted password (AES-256-CBC)

    // Handler Settings
    #[ORM\Column(name: 'BCHECKINTERVAL', type: 'integer')]
    private int $checkInterval = 10; // Minutes

    #[ORM\Column(name: 'BDELETEAFTER', type: 'boolean', options: ['default' => false])]
    private bool $deleteAfter = false;

    #[ORM\Column(name: 'BSTATUS', length: 20, options: ['default' => 'inactive'])]
    private string $status = 'inactive'; // active, inactive, error

    // Departments for AI-based routing (JSON array)
    // Format: [{"id": "1", "email": "support@example.com", "rules": "subject:support", "isDefault": true}]
    #[ORM\Column(name: 'BDEPARTMENTS', type: 'json')]
    private array $departments = [];

    // Timestamps
    #[ORM\Column(name: 'BLASTCHECKED', type: 'string', length: 20, nullable: true)]
    private ?string $lastChecked = null;

    #[ORM\Column(name: 'BCREATED', type: 'string', length: 20)]
    private string $created;

    #[ORM\Column(name: 'BUPDATED', type: 'string', length: 20)]
    private string $updated;

    // Additional config (JSON) - includes SMTP credentials + email filter settings
    // Format: {
    //   "smtp": {"server": "smtp.gmail.com", "port": 587, "username": "user@gmail.com", "password": "encrypted..."},
    //   "email_filter": {"mode": "new", "from_date": null}
    // }
    #[ORM\Column(name: 'BCONFIG', type: 'json', nullable: true)]
    private ?array $config = null;

    public function __construct()
    {
        $this->created = date('YmdHis');
        $this->updated = date('YmdHis');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getMailServer(): string
    {
        return $this->mailServer;
    }

    public function setMailServer(string $mailServer): self
    {
        $this->mailServer = $mailServer;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): self
    {
        $this->port = $port;

        return $this;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function setProtocol(string $protocol): self
    {
        $this->protocol = $protocol;

        return $this;
    }

    public function getSecurity(): string
    {
        return $this->security;
    }

    public function setSecurity(string $security): self
    {
        $this->security = $security;

        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Get encrypted password (for storage).
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Set encrypted password (for storage)
     * Use setDecryptedPassword() to set plaintext password.
     */
    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Get decrypted password
     * Note: Requires EncryptionService - use in Service layer.
     */
    public function getDecryptedPassword(\App\Service\EncryptionService $encryptionService): string
    {
        if (empty($this->password)) {
            return '';
        }

        return $encryptionService->decrypt($this->password);
    }

    /**
     * Set password from plaintext (encrypts automatically)
     * Note: Requires EncryptionService - use in Service layer.
     */
    public function setDecryptedPassword(string $plaintext, \App\Service\EncryptionService $encryptionService): self
    {
        if (empty($plaintext)) {
            $this->password = '';
        } else {
            $this->password = $encryptionService->encrypt($plaintext);
        }

        return $this;
    }

    public function getCheckInterval(): int
    {
        return $this->checkInterval;
    }

    public function setCheckInterval(int $checkInterval): self
    {
        $this->checkInterval = $checkInterval;

        return $this;
    }

    public function isDeleteAfter(): bool
    {
        return $this->deleteAfter;
    }

    public function setDeleteAfter(bool $deleteAfter): self
    {
        $this->deleteAfter = $deleteAfter;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updated = date('YmdHis');

        return $this;
    }

    public function getDepartments(): array
    {
        return $this->departments;
    }

    public function setDepartments(array $departments): self
    {
        $this->departments = $departments;
        $this->updated = date('YmdHis');

        return $this;
    }

    public function getLastChecked(): ?string
    {
        return $this->lastChecked;
    }

    public function setLastChecked(?string $lastChecked): self
    {
        $this->lastChecked = $lastChecked;

        return $this;
    }

    public function getCreated(): string
    {
        return $this->created;
    }

    public function getUpdated(): string
    {
        return $this->updated;
    }

    public function setUpdated(string $updated): self
    {
        $this->updated = $updated;

        return $this;
    }

    public function getConfig(): ?array
    {
        return $this->config;
    }

    public function setConfig(?array $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Set SMTP credentials for email forwarding (encrypts password).
     */
    public function setSmtpCredentials(
        string $smtpServer,
        int $smtpPort,
        string $smtpUsername,
        string $smtpPassword,
        \App\Service\EncryptionService $encryptionService,
        string $smtpSecurity = 'SSL/TLS',
    ): self {
        $config = $this->config ?? [];
        $config['smtp'] = [
            'server' => $smtpServer,
            'port' => $smtpPort,
            'username' => $smtpUsername,
            'password' => empty($smtpPassword) ? '' : $encryptionService->encrypt($smtpPassword),
            'security' => $smtpSecurity,
        ];
        $this->config = $config;
        $this->updated = date('YmdHis');

        return $this;
    }

    /**
     * Get SMTP credentials (decrypts password).
     */
    public function getSmtpCredentials(\App\Service\EncryptionService $encryptionService): ?array
    {
        if (!isset($this->config['smtp'])) {
            return null;
        }

        $smtp = $this->config['smtp'];

        return [
            'server' => $smtp['server'] ?? '',
            'port' => $smtp['port'] ?? 587,
            'username' => $smtp['username'] ?? '',
            'password' => empty($smtp['password']) ? '' : $encryptionService->decrypt($smtp['password']),
            'security' => $smtp['security'] ?? 'SSL/TLS',
        ];
    }

    /**
     * Check if SMTP credentials are configured.
     */
    public function hasSmtpCredentials(): bool
    {
        return isset($this->config['smtp']) && !empty($this->config['smtp']['server']);
    }

    /**
     * Set email filter configuration.
     */
    public function setEmailFilter(string $mode, ?string $fromDate = null): self
    {
        $config = $this->config ?? [];
        $config['email_filter'] = [
            'mode' => $mode, // 'new' or 'historical'
            'from_date' => $fromDate,
        ];
        $this->config = $config;
        $this->updated = date('YmdHis');

        return $this;
    }

    /**
     * Get email filter configuration.
     */
    public function getEmailFilter(): array
    {
        if (!isset($this->config['email_filter'])) {
            return [
                'mode' => 'new',
                'from_date' => null,
            ];
        }

        return $this->config['email_filter'];
    }

    /**
     * Check if handler should process historical emails.
     */
    public function shouldProcessHistoricalEmails(): bool
    {
        $filter = $this->getEmailFilter();

        return 'historical' === $filter['mode'];
    }

    /**
     * Update timestamp on modification.
     */
    public function touch(): self
    {
        $this->updated = date('YmdHis');

        return $this;
    }
}
