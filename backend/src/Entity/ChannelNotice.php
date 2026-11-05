<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChannelNoticeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The owner's AI-notice sentence for one channel binding.
 * Empty is refused: the default sentence is used when no row exists.
 */
#[ORM\Entity(repositoryClass: ChannelNoticeRepository::class)]
#[ORM\Table(name: 'BCHANNELNOTICE')]
#[ORM\UniqueConstraint(name: 'uq_channel_notice', columns: ['BOWNERID', 'BCHANNEL', 'BBINDINGID'])]
class ChannelNotice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BCHANNEL', length: 16)]
    private string $channel;

    #[ORM\Column(name: 'BBINDINGID', type: 'bigint', options: ['default' => 0])]
    private int $bindingId = 0;

    #[ORM\Column(name: 'BTEXT', length: 500)]
    private string $text;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function setOwnerId(int $ownerId): void
    {
        $this->ownerId = $ownerId;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): void
    {
        $this->channel = $channel;
    }

    public function getBindingId(): int
    {
        return $this->bindingId;
    }

    public function setBindingId(int $bindingId): void
    {
        $this->bindingId = $bindingId;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = $text;
    }
}
