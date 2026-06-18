<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TopupRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A one-time prepaid "top-up" purchase that raises a user's cost budget for the
 * current billing period.
 *
 * Bought in fixed EUR steps (e.g. EUR 100) via a Stripe one-time Checkout
 * (mode=payment). The amount is added to the tier's BCOST_BUDGET_MONTHLY when
 * {@see \App\Service\RateLimitService::checkCostBudget()} evaluates the gate,
 * scoped to the period the top-up falls into (BCREATED within the period).
 *
 * Top-ups are NOT a running wallet: they expire with the period, matching the
 * "raise this period's budget" model. The Stripe session id is stored (unique)
 * so webhook retries cannot credit the same purchase twice.
 */
#[ORM\Entity(repositoryClass: TopupRepository::class)]
#[ORM\Table(name: 'BUSER_TOPUPS')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_topup_user')]
#[ORM\Index(columns: ['BCREATED'], name: 'idx_topup_created')]
#[ORM\UniqueConstraint(name: 'uniq_topup_session', columns: ['BSTRIPE_SESSION_ID'])]
class Topup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    /**
     * EUR amount added to the user's budget for the period. Denominated in the
     * same "charged EUR" units as BCOST_BUDGET_MONTHLY (i.e. post-markup spend).
     */
    #[ORM\Column(name: 'BAMOUNT', type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $amount = '0.00';

    #[ORM\Column(name: 'BCURRENCY', length: 8, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    #[ORM\Column(name: 'BSTRIPE_SESSION_ID', length: 255, nullable: true)]
    private ?string $stripeSessionId = null;

    #[ORM\Column(name: 'BSTATUS', length: 32, options: ['default' => 'completed'])]
    private string $status = 'completed';

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

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

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getStripeSessionId(): ?string
    {
        return $this->stripeSessionId;
    }

    public function setStripeSessionId(?string $stripeSessionId): self
    {
        $this->stripeSessionId = $stripeSessionId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): self
    {
        $this->created = $created;

        return $this;
    }
}
