<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Synaplan;

use App\AI\Service\AiFacade;
use Plugin\Synafastbill\Intent\AiCommandExtractorInterface;
use Plugin\Synafastbill\Intent\CommandType;
use Plugin\Synafastbill\Intent\ParsedCommand;

/**
 * AI-backed extraction using the user's configured Synaplan model. Improves the
 * rule-based parser for fuzzy phrasing. Returns null on any doubt or failure so
 * the deterministic rules stay in control. Synaplan-coupled — excluded from the
 * offline gate.
 */
final class AiCommandExtractor implements AiCommandExtractorInterface
{
    private const SYSTEM_PROMPT = <<<PROMPT
        You convert a user's message into a strict JSON command for a FastBill assistant.
        Respond with ONLY compact JSON, no prose. Shape:
          {"command": one of ["invoice.draft.create","payment.register","invoice.list.overdue","help","unknown"],
           "customer_hint": string, "bank_text": string}
        Rules: use "unknown" if unsure; never invent a command that writes data.
        PROMPT;

    /**
     * @param array<string, mixed> $aiOptions
     */
    public function __construct(
        private readonly AiFacade $aiFacade,
        private readonly int $userId,
        private readonly array $aiOptions,
    ) {
    }

    public function extract(string $text): ?ParsedCommand
    {
        try {
            $result = $this->aiFacade->chat([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $text],
            ], $this->userId, $this->aiOptions);
        } catch (\Throwable) {
            return null;
        }

        $content = (string) ($result['content'] ?? '');
        $json = $this->extractJson($content);
        if (null === $json) {
            return null;
        }

        $type = CommandType::tryFrom((string) ($json['command'] ?? 'unknown'));
        if (!in_array($type, [
            CommandType::CreateDraftInvoice,
            CommandType::RegisterPayment,
            CommandType::ShowOverdue,
            CommandType::Help,
        ], true)) {
            return null;
        }

        return new ParsedCommand($type, [
            'customer_hint' => (string) ($json['customer_hint'] ?? ''),
            'bank_text' => (string) ($json['bank_text'] ?? $text),
        ], $text);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $content): ?array
    {
        if (1 !== preg_match('/\{.*\}/s', $content, $matches)) {
            return null;
        }
        $decoded = json_decode($matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }
}
