<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WidgetEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Real-time widget event (takeover, handback, message, typing, notification).
 *
 * This table is the shared, cluster-wide transport for the SSE layer. It
 * deliberately lives in the (Galera-replicated) database rather than a
 * node-local cache: web nodes sit behind a round-robin load balancer, so an
 * event published while handling a POST on one node MUST be visible to the SSE
 * stream held open on a different node. A node-local filesystem cache cannot do
 * that, which is what caused operator/visitor messages to only appear after a
 * manual reload (the reload reads the shared DB).
 *
 * Rows are append-only and short-lived (see BEXPIRES). Each publish is a single
 * INSERT, so there is no read-modify-write race (the previous cache approach
 * rewrote one array and silently dropped concurrent events).
 *
 * Interim design: a Redis-backed {@see \App\Service\WidgetEventStoreInterface}
 * implementation is planned to replace DB polling with push.
 */
#[ORM\Entity(repositoryClass: WidgetEventRepository::class)]
#[ORM\Table(name: 'BWIDGET_EVENTS')]
#[ORM\Index(columns: ['BWIDGETID', 'BSESSIONID', 'BID'], name: 'idx_widget_event_stream')]
#[ORM\Index(columns: ['BEXPIRES'], name: 'idx_widget_event_expires')]
class WidgetEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BWIDGETID', length: 64)]
    private string $widgetId;

    /**
     * Session identifier, or the literal 'notifications' for the widget-owner
     * notification stream (mirrors the previous cache keying).
     */
    #[ORM\Column(name: 'BSESSIONID', length: 128)]
    private string $sessionId;

    #[ORM\Column(name: 'BTYPE', length: 32)]
    private string $type;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'BPAYLOAD', type: 'json')]
    private array $payload = [];

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BEXPIRES', type: 'bigint')]
    private int $expires;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $widgetId, string $sessionId, string $type, array $payload, int $expires)
    {
        $this->widgetId = $widgetId;
        $this->sessionId = $sessionId;
        $this->type = $type;
        $this->payload = $payload;
        $this->created = time();
        $this->expires = $expires;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWidgetId(): string
    {
        return $this->widgetId;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getExpires(): int
    {
        return $this->expires;
    }
}
