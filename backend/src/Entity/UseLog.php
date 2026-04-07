<?php

namespace App\Entity;

use App\Repository\UseLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UseLogRepository::class)]
#[ORM\Table(name: 'BUSELOG')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_uselog_user')]
#[ORM\Index(columns: ['BUNIXTIMES'], name: 'idx_uselog_time')]
#[ORM\Index(columns: ['BACTION'], name: 'idx_uselog_action')]
#[ORM\Index(columns: ['BPROVIDER'], name: 'idx_uselog_provider')]
#[ORM\Index(columns: ['BMODEL_ID'], name: 'idx_uselog_model')]
class UseLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'BUSERID', referencedColumnName: 'BID')]
    private ?User $user = null;

    #[ORM\Column(name: 'BUNIXTIMES', type: 'bigint')]
    private int $unixTimestamp;

    #[ORM\Column(name: 'BACTION', length: 64)]
    private string $action;

    #[ORM\Column(name: 'BPROVIDER', length: 32, options: ['default' => ''])]
    private string $provider = '';

    #[ORM\Column(name: 'BMODEL', length: 128, options: ['default' => ''])]
    private string $model = '';

    #[ORM\Column(name: 'BTOKENS', type: 'integer', options: ['default' => 0])]
    private int $tokens = 0;

    #[ORM\Column(name: 'BPROMPT_TOKENS', type: 'integer', options: ['default' => 0])]
    private int $promptTokens = 0;

    #[ORM\Column(name: 'BCOMPLETION_TOKENS', type: 'integer', options: ['default' => 0])]
    private int $completionTokens = 0;

    #[ORM\Column(name: 'BCACHED_TOKENS', type: 'integer', options: ['default' => 0])]
    private int $cachedTokens = 0;

    #[ORM\Column(name: 'BCACHE_CREATION_TOKENS', type: 'integer', options: ['default' => 0])]
    private int $cacheCreationTokens = 0;

    #[ORM\Column(name: 'BESTIMATED', type: 'boolean', options: ['default' => false])]
    private bool $estimated = false;

    #[ORM\ManyToOne(targetEntity: Model::class)]
    #[ORM\JoinColumn(name: 'BMODEL_ID', referencedColumnName: 'BID', nullable: true)]
    private ?Model $modelEntity = null;

    #[ORM\Column(name: 'BPRICE_SNAPSHOT', type: 'json', nullable: true)]
    private ?array $priceSnapshot = null;

    #[ORM\Column(name: 'BCOST', type: 'decimal', precision: 10, scale: 6, options: ['default' => 0])]
    private string $cost = '0.000000';

    #[ORM\Column(name: 'BLATENCY', type: 'integer', options: ['default' => 0])]
    private int $latency = 0;

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => 'success'])]
    private string $status = 'success';

    #[ORM\Column(name: 'BERROR', type: 'text', options: ['default' => ''])]
    private string $error = '';

    #[ORM\Column(name: 'BMETADATA', type: 'json')]
    private array $metadata = [];

    public function __construct()
    {
        $this->unixTimestamp = time();
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        if ($user) {
            $this->userId = $user->getId();
        }

        return $this;
    }

    public function getUnixTimestamp(): int
    {
        return $this->unixTimestamp;
    }

    public function setUnixTimestamp(int $unixTimestamp): self
    {
        $this->unixTimestamp = $unixTimestamp;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getTokens(): int
    {
        return $this->tokens;
    }

    public function setTokens(int $tokens): self
    {
        $this->tokens = $tokens;

        return $this;
    }

    public function getCost(): string
    {
        return $this->cost;
    }

    public function setCost(string $cost): self
    {
        $this->cost = $cost;

        return $this;
    }

    public function getLatency(): int
    {
        return $this->latency;
    }

    public function setLatency(int $latency): self
    {
        $this->latency = $latency;

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

    public function getError(): string
    {
        return $this->error;
    }

    public function setError(string $error): self
    {
        $this->error = $error;

        return $this;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function setPromptTokens(int $promptTokens): self
    {
        $this->promptTokens = $promptTokens;

        return $this;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function setCompletionTokens(int $completionTokens): self
    {
        $this->completionTokens = $completionTokens;

        return $this;
    }

    public function getCachedTokens(): int
    {
        return $this->cachedTokens;
    }

    public function setCachedTokens(int $cachedTokens): self
    {
        $this->cachedTokens = $cachedTokens;

        return $this;
    }

    public function getCacheCreationTokens(): int
    {
        return $this->cacheCreationTokens;
    }

    public function setCacheCreationTokens(int $cacheCreationTokens): self
    {
        $this->cacheCreationTokens = $cacheCreationTokens;

        return $this;
    }

    public function isEstimated(): bool
    {
        return $this->estimated;
    }

    public function setEstimated(bool $estimated): self
    {
        $this->estimated = $estimated;

        return $this;
    }

    public function getModelEntity(): ?Model
    {
        return $this->modelEntity;
    }

    public function setModelEntity(?Model $modelEntity): self
    {
        $this->modelEntity = $modelEntity;

        return $this;
    }

    public function getPriceSnapshot(): ?array
    {
        return $this->priceSnapshot;
    }

    public function setPriceSnapshot(?array $priceSnapshot): self
    {
        $this->priceSnapshot = $priceSnapshot;

        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }
}
