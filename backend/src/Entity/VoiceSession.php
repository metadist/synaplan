<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VoiceSessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VoiceSessionRepository::class)]
#[ORM\Table(name: 'BVOICESESSIONS')]
#[ORM\Index(columns: ['BUSERID', 'BSTATUS'], name: 'idx_voice_session_user_status')]
class VoiceSession
{
    public const OPEN_STATUSES = ['connecting', 'live', 'ending'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'integer')]
    private int $userId = 0;

    #[ORM\Column(name: 'BCHATID', type: 'integer', nullable: true)]
    private ?int $chatId = null;

    #[ORM\Column(name: 'BMODELID', type: 'integer')]
    private int $modelId = 0;

    #[ORM\Column(name: 'BPROVIDER', length: 32)]
    private string $provider = '';

    #[ORM\Column(name: 'BPROVIDERSESSIONID', length: 128, nullable: true)]
    private ?string $providerSessionId = null;

    #[ORM\Column(name: 'BSTATUS', length: 16)]
    private string $status = 'live';

    #[ORM\Column(name: 'BENDREASON', length: 32, nullable: true)]
    private ?string $endReason = null;

    #[ORM\Column(name: 'BSTARTED', type: 'integer')]
    private int $started = 0;

    #[ORM\Column(name: 'BENDED', type: 'integer', nullable: true)]
    private ?int $ended = null;

    #[ORM\Column(name: 'BSECONDS', type: 'integer', options: ['default' => 0])]
    private int $seconds = 0;

    #[ORM\Column(name: 'BUSELOGID', type: 'integer', nullable: true)]
    private ?int $useLogId = null;

    #[ORM\Column(name: 'BCLIENT', length: 16)]
    private string $client = 'web';

    #[ORM\Column(name: 'BTRANSPORT', length: 16)]
    private string $transport = 'relay';

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

    public function getChatId(): ?int
    {
        return $this->chatId;
    }

    public function setChatId(?int $chatId): self
    {
        $this->chatId = $chatId;

        return $this;
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

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getProviderSessionId(): ?string
    {
        return $this->providerSessionId;
    }

    public function setProviderSessionId(?string $providerSessionId): self
    {
        $this->providerSessionId = $providerSessionId;

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

    public function getEndReason(): ?string
    {
        return $this->endReason;
    }

    public function setEndReason(?string $endReason): self
    {
        $this->endReason = $endReason;

        return $this;
    }

    public function getStarted(): int
    {
        return $this->started;
    }

    public function setStarted(int $started): self
    {
        $this->started = $started;

        return $this;
    }

    public function getEnded(): ?int
    {
        return $this->ended;
    }

    public function setEnded(?int $ended): self
    {
        $this->ended = $ended;

        return $this;
    }

    public function getSeconds(): int
    {
        return $this->seconds;
    }

    public function setSeconds(int $seconds): self
    {
        $this->seconds = $seconds;

        return $this;
    }

    public function getUseLogId(): ?int
    {
        return $this->useLogId;
    }

    public function setUseLogId(?int $useLogId): self
    {
        $this->useLogId = $useLogId;

        return $this;
    }

    public function getClient(): string
    {
        return $this->client;
    }

    public function setClient(string $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function setTransport(string $transport): self
    {
        $this->transport = $transport;

        return $this;
    }
}
