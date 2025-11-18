<?php

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'BSUBSCRIPTIONS')]
#[ORM\Index(columns: ['BLEVEL'], name: 'BLEVEL')]
#[ORM\Index(columns: ['BACTIVE'], name: 'BACTIVE')]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BNAME', length: 64)]
    private string $name;

    #[ORM\Column(name: 'BLEVEL', length: 32)]
    private string $level; // NEW, PRO, TEAM, BUSINESS

    #[ORM\Column(name: 'BPRICE_MONTHLY', type: 'decimal', precision: 10, scale: 2)]
    private string $priceMonthly;

    #[ORM\Column(name: 'BPRICE_YEARLY', type: 'decimal', precision: 10, scale: 2)]
    private string $priceYearly;

    #[ORM\Column(name: 'BDESCRIPTION', type: 'text')]
    private string $description;

    #[ORM\Column(name: 'BACTIVE', type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'BSTRIPE_MONTHLY_ID', length: 128, nullable: true)]
    private ?string $stripeMonthlyId = null;

    #[ORM\Column(name: 'BSTRIPE_YEARLY_ID', length: 128, nullable: true)]
    private ?string $stripeYearlyId = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function getPriceMonthly(): string
    {
        return $this->priceMonthly;
    }

    public function setPriceMonthly(string $priceMonthly): self
    {
        $this->priceMonthly = $priceMonthly;
        return $this;
    }

    public function getPriceYearly(): string
    {
        return $this->priceYearly;
    }

    public function setPriceYearly(string $priceYearly): self
    {
        $this->priceYearly = $priceYearly;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
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

    public function getStripeMonthlyId(): ?string
    {
        return $this->stripeMonthlyId;
    }

    public function setStripeMonthlyId(?string $stripeMonthlyId): self
    {
        $this->stripeMonthlyId = $stripeMonthlyId;
        return $this;
    }

    public function getStripeYearlyId(): ?string
    {
        return $this->stripeYearlyId;
    }

    public function setStripeYearlyId(?string $stripeYearlyId): self
    {
        $this->stripeYearlyId = $stripeYearlyId;
        return $this;
    }
}

