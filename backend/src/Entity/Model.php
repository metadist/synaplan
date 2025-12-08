<?php

namespace App\Entity;

use App\Repository\ModelRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ModelRepository::class)]
#[ORM\Table(name: 'BMODELS')]
#[ORM\Index(columns: ['BTAG'], name: 'idx_tag')]
#[ORM\Index(columns: ['BSERVICE'], name: 'idx_service')]
class Model
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BSERVICE', length: 32)]
    private string $service = '';

    #[ORM\Column(name: 'BNAME', length: 48)]
    private string $name = '';

    #[ORM\Column(name: 'BTAG', length: 24)]
    private string $tag = '';

    #[ORM\Column(name: 'BSELECTABLE', type: 'integer')]
    private int $selectable = 0;

    #[ORM\Column(name: 'BPROVID', length: 96)]
    private string $providerId = '';

    #[ORM\Column(name: 'BPRICEIN', type: 'float')]
    private float $priceIn = 0.0;

    #[ORM\Column(name: 'BINUNIT', length: 24)]
    private string $inUnit = 'per1M';

    #[ORM\Column(name: 'BPRICEOUT', type: 'float')]
    private float $priceOut = 0.0;

    #[ORM\Column(name: 'BOUTUNIT', length: 24)]
    private string $outUnit = 'per1M';

    #[ORM\Column(name: 'BQUALITY', type: 'float')]
    private float $quality = 7.0;

    #[ORM\Column(name: 'BRATING', type: 'float')]
    private float $rating = 0.5;

    #[ORM\Column(name: 'BISDEFAULT', type: 'integer', options: ['default' => 0])]
    private int $isDefault = 0;

    #[ORM\Column(name: 'BACTIVE', type: 'integer', options: ['default' => 1])]
    private int $active = 1;

    #[ORM\Column(name: 'BDESCRIPTION', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'BJSON', type: 'json')]
    private array $json = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function setService(string $service): self
    {
        $this->service = $service;

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

    public function getTag(): string
    {
        return $this->tag;
    }

    public function setTag(string $tag): self
    {
        $this->tag = $tag;

        return $this;
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function setProviderId(string $providerId): self
    {
        $this->providerId = $providerId;

        return $this;
    }

    public function getSelectable(): int
    {
        return $this->selectable;
    }

    public function setSelectable(int $selectable): self
    {
        $this->selectable = $selectable;

        return $this;
    }

    public function getPriceIn(): float
    {
        return $this->priceIn;
    }

    public function setPriceIn(float $priceIn): self
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

    public function getPriceOut(): float
    {
        return $this->priceOut;
    }

    public function setPriceOut(float $priceOut): self
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

    public function getQuality(): float
    {
        return $this->quality;
    }

    public function setQuality(float $quality): self
    {
        $this->quality = $quality;

        return $this;
    }

    public function getRating(): float
    {
        return $this->rating;
    }

    public function setRating(float $rating): self
    {
        $this->rating = $rating;

        return $this;
    }

    public function getIsDefault(): int
    {
        return $this->isDefault;
    }

    public function setIsDefault(int $isDefault): self
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function getJson(): array
    {
        return $this->json;
    }

    public function setJson(array $json): self
    {
        $this->json = $json;

        return $this;
    }

    public function getActive(): int
    {
        return $this->active;
    }

    public function setActive(int $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Check if this is a system model that cannot be changed by users.
     */
    public function isSystemModel(): bool
    {
        // System models haben BISDEFAULT=1 oder spezielle JSON-Flag
        return 1 === $this->isDefault || ($this->json['is_system'] ?? false);
    }

    /**
     * Get model features from JSON.
     *
     * @return array Features like ['reasoning', 'vision', etc.]
     */
    public function getFeatures(): array
    {
        return $this->json['features'] ?? [];
    }

    /**
     * Check if model has a specific feature.
     *
     * @param string $feature Feature name (e.g. 'reasoning', 'vision')
     */
    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->getFeatures(), true);
    }
}
