<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SignupCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An admission ticket an administrator hands out: whoever holds the code may
 * create an account without paying. Lookups use the SHA-256 hash; the code
 * itself is kept so the administrator can show and copy it again. Codes from
 * before that have only the hash and the four-character prefix.
 *
 * The tier is a property of the code (default member), never of the plan
 * the person clicked — a leaked member code opens member accounts only, and
 * {@see $maxUses} bounds how many.
 */
#[ORM\Entity(repositoryClass: SignupCodeRepository::class)]
#[ORM\Table(name: 'BSIGNUP_CODES')]
#[ORM\Index(columns: ['BACTIVE', 'BEXPIRES'], name: 'idx_signup_code_live')]
#[ORM\UniqueConstraint(name: 'uniq_signup_code_hash', columns: ['BCODEHASH'])]
class SignupCode
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const LENGTH = 12;
    public const PREFIX_LENGTH = 4;

    public const LEVEL_MEMBER = 'NEW';
    public const LEVELS = [self::LEVEL_MEMBER, 'PRO', 'TEAM', 'BUSINESS'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BCODEHASH', length: 64)]
    private string $codeHash = '';

    #[ORM\Column(name: 'BPREFIX', length: 8)]
    private string $prefix = '';

    #[ORM\Column(name: 'BCODE', length: 16, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(name: 'BLABEL', length: 120)]
    private string $label = '';

    #[ORM\Column(name: 'BLEVEL', length: 16)]
    private string $level = self::LEVEL_MEMBER;

    #[ORM\Column(name: 'BMAXUSES', type: 'integer', nullable: true)]
    private ?int $maxUses = 1;

    /**
     * Incremented by the redeem UPDATE, never by the unit of work. An
     * updatable column lets a manager that still holds the old count flush
     * that count back over a redemption that already happened.
     */
    #[ORM\Column(name: 'BUSES', type: 'integer', updatable: false, options: ['default' => 0])]
    private int $uses = 0;

    /** Unix timestamp; null means the code never expires. */
    #[ORM\Column(name: 'BEXPIRES', type: 'bigint', nullable: true)]
    private ?int $expires = null;

    #[ORM\Column(name: 'BACTIVE', type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'BCREATEDBY', type: 'bigint')]
    private int $createdBy = 0;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    public function __construct()
    {
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function setCodeHash(string $codeHash): self
    {
        $this->codeHash = $codeHash;

        return $this;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): self
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown sign-up code level "%s"; expected one of %s', $level, implode(', ', self::LEVELS)));
        }
        $this->level = $level;

        return $this;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): self
    {
        if (null !== $maxUses && $maxUses < 1) {
            throw new \InvalidArgumentException('A sign-up code allows at least one use or has no limit (null).');
        }
        $this->maxUses = $maxUses;

        return $this;
    }

    public function getUses(): int
    {
        return $this->uses;
    }

    public function getExpires(): ?int
    {
        return $this->expires;
    }

    public function setExpires(?int $expires): self
    {
        $this->expires = $expires;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedBy(): int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(int $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function isExpired(?int $now = null): bool
    {
        return null !== $this->expires && $this->expires <= ($now ?? time());
    }

    public function isExhausted(): bool
    {
        return null !== $this->maxUses && $this->uses >= $this->maxUses;
    }

    /** Active, not expired, not used up. */
    public function isRedeemable(?int $now = null): bool
    {
        return $this->active && !$this->isExpired($now) && !$this->isExhausted();
    }

    /**
     * Remember the count the redeem UPDATE just stored. In memory only:
     * the column is not updatable, so a later flush cannot write it back.
     */
    public function syncUses(int $uses): void
    {
        $this->uses = $uses;
    }
}
