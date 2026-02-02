<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WidgetEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stores real-time events for widget sessions.
 * Events are consumed via SSE and auto-cleaned after 24 hours.
 */
#[ORM\Entity(repositoryClass: WidgetEventRepository::class)]
#[ORM\Table(name: 'BWIDGET_EVENTS')]
#[ORM\Index(columns: ['BWIDGETID', 'BSESSIONID', 'BCREATED'], name: 'idx_widget_session_events')]
#[ORM\Index(columns: ['BCREATED'], name: 'idx_event_created')]
class WidgetEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BWIDGETID', length: 64)]
    private string $widgetId;

    #[ORM\Column(name: 'BSESSIONID', length: 64)]
    private string $sessionId;

    /**
     * Event type: takeover, handback, message, typing.
     */
    #[ORM\Column(name: 'BTYPE', length: 32)]
    private string $type;

    /**
     * JSON payload of the event.
     */
    #[ORM\Column(name: 'BPAYLOAD', type: 'text')]
    private string $payload = '{}';

    /**
     * Unix timestamp when the event was created.
     */
    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    /**
     * Whether the event has been consumed (for cleanup).
     */
    #[ORM\Column(name: 'BCONSUMED', type: 'boolean')]
    private bool $consumed = false;

    public function __construct()
    {
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWidgetId(): string
    {
        return $this->widgetId;
    }

    public function setWidgetId(string $widgetId): self
    {
        $this->widgetId = $widgetId;

        return $this;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return json_decode($this->payload, true) ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): self
    {
        $this->payload = json_encode($payload, JSON_UNESCAPED_UNICODE);

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

    public function isConsumed(): bool
    {
        return $this->consumed;
    }

    public function setConsumed(bool $consumed): self
    {
        $this->consumed = $consumed;

        return $this;
    }
}
