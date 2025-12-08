<?php

namespace App\Entity;

use App\Repository\ConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConfigRepository::class)]
#[ORM\Table(name: 'BCONFIG')]
#[ORM\Index(columns: ['BOWNERID', 'BGROUP', 'BSETTING'], name: 'idx_config_lookup')]
#[ORM\Index(columns: ['BGROUP'], name: 'idx_group')]
#[ORM\Index(columns: ['BSETTING'], name: 'idx_setting')]
class Config
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId = 0;

    #[ORM\Column(name: 'BGROUP', length: 64)]
    private string $group = '';

    #[ORM\Column(name: 'BSETTING', length: 96)]
    private string $setting = '';

    #[ORM\Column(name: 'BVALUE', length: 250)]
    private string $value = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function setOwnerId(int $ownerId): self
    {
        $this->ownerId = $ownerId;

        return $this;
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    public function setGroup(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function getSetting(): string
    {
        return $this->setting;
    }

    public function setSetting(string $setting): self
    {
        $this->setting = $setting;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }
}
