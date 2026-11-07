<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command;

use Plugin\Synafastbill\Api\FastBillClient;
use Plugin\Synafastbill\Intent\CommandType;
use Plugin\Synafastbill\Localization\Translator;

/**
 * Per-turn context handed to a command handler. Carries the collaborators a
 * handler may use and mediates the pending-session lifecycle: a handler calls
 * {@see remember()} to keep a multi-turn flow alive or {@see forget()} to end
 * it. The router reads {@see pendingState()} / {@see shouldForget()} afterwards
 * to persist the decision.
 */
final class HandlerContext
{
    /** @var array<string, mixed>|null */
    private ?array $pending = null;

    private bool $forget = false;

    /**
     * @param array<string, mixed> $sessionData
     */
    public function __construct(
        public readonly FastBillClient $client,
        public readonly ClockInterface $clock,
        public readonly PluginConfig $config,
        public readonly Translator $t,
        private readonly ?string $sessionStep = null,
        private readonly array $sessionData = [],
        private readonly int $sessionTtlSeconds = 1800,
    ) {
    }

    public function step(): ?string
    {
        return $this->sessionStep;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->sessionData;
    }

    /**
     * Keep (or advance) a multi-turn flow: persist the given step + data under
     * the owning command until the next turn.
     *
     * @param array<string, mixed> $data
     */
    public function remember(CommandType $owner, string $step, array $data): void
    {
        $this->forget = false;
        $this->pending = [
            'handler' => $owner->value,
            'step' => $step,
            'data' => $data,
            'expires_at' => time() + $this->sessionTtlSeconds,
        ];
    }

    /**
     * End the current flow; the router clears any stored session.
     */
    public function forget(): void
    {
        $this->forget = true;
        $this->pending = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingState(): ?array
    {
        return $this->pending;
    }

    public function shouldForget(): bool
    {
        return $this->forget;
    }
}
