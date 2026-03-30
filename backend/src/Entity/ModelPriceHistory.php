<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ModelPriceHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ModelPriceHistoryRepository::class)]
#[ORM\Table(name: 'BMODEL_PRICE_HISTORY')]
#[ORM\Index(columns: ['BMODEL_ID'], name: 'idx_mph_model')]
#[ORM\Index(columns: ['BVALID_FROM'], name: 'idx_mph_valid_from')]
#[ORM\Index(columns: ['BVALID_TO'], name: 'idx_mph_valid_to')]
class ModelPriceHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Model::class)]
    #[ORM\JoinColumn(name: 'BMODEL_ID', referencedColumnName: 'BID', nullable: false)]
    private Model $model;

    #[ORM\Column(name: 'BPRICEIN', type: 'decimal', precision: 10, scale: 8)]
    private string $priceIn = '0.00000000';

    #[ORM\Column(name: 'BINUNIT', length: 24, options: ['default' => 'per1M'])]
    private string $inUnit = 'per1M';

    #[ORM\Column(name: 'BPRICEOUT', type: 'decimal', precision: 10, scale: 8)]
    private string $priceOut = '0.00000000';

    #[ORM\Column(name: 'BOUTUNIT', length: 24, options: ['default' => 'per1M'])]
    private string $outUnit = 'per1M';

    #[ORM\Column(name: 'BCACHEPRICEIN', type: 'decimal', precision: 10, scale: 8, nullable: true)]
    private ?string $cachePriceIn = null;

    #[ORM\Column(name: 'BSOURCE', length: 32, options: ['default' => 'manual'])]
    private string $source = 'manual';

    #[ORM\Column(name: 'BVALID_FROM', type: 'datetime')]
    private \DateTimeInterface $validFrom;

    #[ORM\Column(name: 'BVALID_TO', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $validTo = null;

    #[ORM\Column(name: 'BCREATED_AT', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->validFrom = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function setModel(Model $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getPriceIn(): string
    {
        return $this->priceIn;
    }

    public function setPriceIn(string $priceIn): self
    {
        $this->priceIn = $priceIn;

        return $this;
    }

    public function getInUnit(): string
    {
        return $this->inUnit;
    }

    public function setInUnit(string $inUnit): self
    {
        $this->inUnit = $inUnit;

        return $this;
    }

    public function getPriceOut(): string
    {
        return $this->priceOut;
    }

    public function setPriceOut(string $priceOut): self
    {
        $this->priceOut = $priceOut;

        return $this;
    }

    public function getOutUnit(): string
    {
        return $this->outUnit;
    }

    public function setOutUnit(string $outUnit): self
    {
        $this->outUnit = $outUnit;

        return $this;
    }

    public function getCachePriceIn(): ?string
    {
        return $this->cachePriceIn;
    }

    public function setCachePriceIn(?string $cachePriceIn): self
    {
        $this->cachePriceIn = $cachePriceIn;

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

    public function getValidFrom(): \DateTimeInterface
    {
        return $this->validFrom;
    }

    public function setValidFrom(\DateTimeInterface $validFrom): self
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidTo(): ?\DateTimeInterface
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeInterface $validTo): self
    {
        $this->validTo = $validTo;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function isCurrentlyValid(): bool
    {
        return null === $this->validTo;
    }
}
