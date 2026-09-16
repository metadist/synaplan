<?php

declare(strict_types=1);

namespace App\AI\Messages;

use Symfony\Component\HttpFoundation\Request;

/**
 * Opt-in desktop headers on POST /v1/messages. Unknown x-synaplan-* headers
 * are ignored. Invalid / missing values become null (today's behaviour).
 */
final readonly class DesktopTurnOptions
{
    public const HEADER_AGENT_ID = 'x-synaplan-agent-id';
    public const HEADER_RAG_GROUP_KEY = 'x-synaplan-rag-group-key';

    public function __construct(
        public ?int $agentId,
        public ?string $ragGroupKey,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function fromRequest(Request $request): self
    {
        $rawAgent = trim((string) $request->headers->get(self::HEADER_AGENT_ID, ''));
        $agentId = ctype_digit($rawAgent) ? (int) $rawAgent : null;
        if (null !== $agentId && $agentId < 1) {
            $agentId = null;
        }

        $rag = trim((string) $request->headers->get(self::HEADER_RAG_GROUP_KEY, ''));

        return new self($agentId, '' !== $rag ? $rag : null);
    }

    public function isEmpty(): bool
    {
        return null === $this->agentId && null === $this->ragGroupKey;
    }
}
