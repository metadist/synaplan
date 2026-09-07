<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupConfigRepository::class)]
#[ORM\Table(name: 'BGROUPCONFIG')]
#[ORM\UniqueConstraint(name: 'uniq_groupconfig_group_setting', columns: ['BGROUPID', 'BGROUP', 'BSETTING'])]
#[ORM\Index(columns: ['BGROUPID'], name: 'idx_groupconfig_group')]
class GroupConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BGROUPID', type: 'bigint')]
    private int $groupId = 0;

    #[ORM\Column(name: 'BGROUP', length: 64)]
    private string $group = '';

    #[ORM\Column(name: 'BSETTING', length: 96)]
    private string $setting = '';

    #[ORM\Column(name: 'BVALUE', type: Types::TEXT)]
    private string $value = '';

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct()
    {
        $now = time();
        $this->created = $now;
        $this->updated = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function setGroupId(int $groupId): self
    {
        $this->groupId = $groupId;

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
        $this->updated = time();

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }
}
