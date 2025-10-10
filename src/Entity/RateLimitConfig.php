<?php

namespace App\Entity;

use App\Repository\RateLimitConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RateLimitConfigRepository::class)]
#[ORM\Table(name: 'BRATELIMITS_CONFIG')]
#[ORM\Index(columns: ['BSCOPE'], name: 'idx_ratelimit_scope')]
#[ORM\Index(columns: ['BPLAN'], name: 'idx_ratelimit_plan')]
#[ORM\UniqueConstraint(name: 'unique_scope_plan', columns: ['BSCOPE', 'BPLAN'])]
class RateLimitConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BSCOPE', length: 64)]
    private string $scope; // 'api_calls', 'widget_messages', 'ai_requests', 'file_uploads'

    #[ORM\Column(name: 'BPLAN', length: 32)]
    private string $plan; // 'NEW', 'PRO', 'TEAM', 'BUSINESS'

    #[ORM\Column(name: 'BLIMIT', type: 'integer')]
    private int $limit; // z.B. 100 requests

    #[ORM\Column(name: 'BWINDOW', type: 'integer')]
    private int $window; // in Sekunden, z.B. 60 für 1 Minute

    #[ORM\Column(name: 'BDESCRIPTION', type: 'text', options: ['default' => ''])]
    private string $description = '';

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

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): self
    {
        $this->scope = $scope;
        return $this;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function setPlan(string $plan): self
    {
        $this->plan = $plan;
        return $this;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setLimit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function getWindow(): int
    {
        return $this->window;
    }

    public function setWindow(int $window): self
    {
        $this->window = $window;
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

    public function getCreated(): int
    {
        return $this->created;
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

    public function touch(): self
    {
        $this->updated = time();
        return $this;
    }
}

