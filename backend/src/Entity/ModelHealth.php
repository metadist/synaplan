<?php

declare(strict_types=1);

namespace App\Entity;

use App\AI\Health\ModelHealthState;
use App\Repository\ModelHealthRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persisted health of one BMODELS row.
 *
 * Rolling success/failure counters live in Redis because they are written on
 * every AI call; this table holds what must survive a cache flush — above all
 * {@see self::$autoDisabled}. Without that provenance nobody could tell a model
 * an operator switched off from one the automation retired, and the automation
 * could never safely switch it back on.
 *
 * Deliberately no foreign key to BMODELS: several FKs in this schema have no
 * ON DELETE CASCADE and force delete-ordering gymnastics in migrations on the
 * Galera cluster. An orphaned row here is harmless and gets pruned by the
 * health check.
 */
#[ORM\Entity(repositoryClass: ModelHealthRepository::class)]
#[ORM\Table(name: 'BMODELHEALTH')]
#[ORM\UniqueConstraint(name: 'uniq_modelhealth_model', columns: ['BMODELID'])]
#[ORM\Index(columns: ['BSTATE'], name: 'idx_modelhealth_state')]
class ModelHealth
{
    /** Recorded from a free provider catalog lookup. */
    public const SOURCE_PROBE = 'probe';

    /** Recorded from a real user request that happened anyway. */
    public const SOURCE_TRAFFIC = 'traffic';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BMODELID', type: 'bigint')]
    private int $modelId = 0;

    // Spelled out rather than read from the enum because an attribute argument
    // must be a constant expression. Must stay equal to the migration's DEFAULT
    // and to ModelHealthState::Unknown->value, or doctrine:schema:validate fails.
    #[ORM\Column(name: 'BSTATE', length: 16, options: ['default' => 'unknown'])]
    private string $state = ModelHealthState::Unknown->value;

    #[ORM\Column(name: 'BSOURCE', length: 16, options: ['default' => self::SOURCE_PROBE])]
    private string $source = self::SOURCE_PROBE;

    /** {@see \App\AI\Health\FailureKind} value, or null while healthy. */
    #[ORM\Column(name: 'BKIND', length: 16, nullable: true)]
    private ?string $kind = null;

    #[ORM\Column(name: 'BMESSAGE', type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(name: 'BLASTCHECK', type: 'bigint', options: ['default' => 0])]
    private int $lastCheck = 0;

    #[ORM\Column(name: 'BLASTSUCCESS', type: 'bigint', options: ['default' => 0])]
    private int $lastSuccess = 0;

    #[ORM\Column(name: 'BLASTFAILURE', type: 'bigint', options: ['default' => 0])]
    private int $lastFailure = 0;

    /** True only when THIS automation set BACTIVE = 0. */
    #[ORM\Column(name: 'BAUTODISABLED', type: 'integer', options: ['default' => 0])]
    private int $autoDisabled = 0;

    #[ORM\Column(name: 'BAUTODISABLEDAT', type: 'bigint', options: ['default' => 0])]
    private int $autoDisabledAt = 0;

    /**
     * Until this timestamp the automation may report but must not switch this
     * model off. Set when an operator re-enables a model by hand, so human and
     * automation stop fighting over the same row.
     */
    #[ORM\Column(name: 'BSUPPRESSUNTIL', type: 'bigint', options: ['default' => 0])]
    private int $suppressUntil = 0;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint', options: ['default' => 0])]
    private int $updated = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModelId(): int
    {
        return $this->modelId;
    }

    public function setModelId(int $modelId): self
    {
        $this->modelId = $modelId;

        return $this;
    }

    public function getState(): ModelHealthState
    {
        return ModelHealthState::tryFrom($this->state) ?? ModelHealthState::Unknown;
    }

    public function setState(ModelHealthState $state): self
    {
        $this->state = $state->value;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getKind(): ?string
    {
        return $this->kind;
    }

    public function setKind(?string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = null === $message ? null : mb_substr($message, 0, 2000);

        return $this;
    }

    public function getLastCheck(): int
    {
        return $this->lastCheck;
    }

    public function setLastCheck(int $lastCheck): self
    {
        $this->lastCheck = $lastCheck;

        return $this;
    }

    public function getLastSuccess(): int
    {
        return $this->lastSuccess;
    }

    public function setLastSuccess(int $lastSuccess): self
    {
        $this->lastSuccess = $lastSuccess;

        return $this;
    }

    public function getLastFailure(): int
    {
        return $this->lastFailure;
    }

    public function setLastFailure(int $lastFailure): self
    {
        $this->lastFailure = $lastFailure;

        return $this;
    }

    public function isAutoDisabled(): bool
    {
        return 1 === $this->autoDisabled;
    }

    public function setAutoDisabled(bool $autoDisabled): self
    {
        $this->autoDisabled = $autoDisabled ? 1 : 0;

        return $this;
    }

    public function getAutoDisabledAt(): int
    {
        return $this->autoDisabledAt;
    }

    public function setAutoDisabledAt(int $autoDisabledAt): self
    {
        $this->autoDisabledAt = $autoDisabledAt;

        return $this;
    }

    public function getSuppressUntil(): int
    {
        return $this->suppressUntil;
    }

    public function setSuppressUntil(int $suppressUntil): self
    {
        $this->suppressUntil = $suppressUntil;

        return $this;
    }

    public function isSuppressed(?int $now = null): bool
    {
        return $this->suppressUntil > ($now ?? time());
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function setUpdated(int $updated): self
    {
        $this->updated = $updated;

        return $this;
    }
}
