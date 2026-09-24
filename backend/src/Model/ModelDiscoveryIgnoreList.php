<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Reviewed decisions to ignore specific OpenRouter model ids.
 *
 * Entries are exact OpenRouter ids only — never a pattern or prefix. Each
 * records why we skip the id and when that decision was made. Delete an entry
 * when the id disappears upstream or lands in {@see ModelCatalog}.
 */
final class ModelDiscoveryIgnoreList
{
    /**
     * @var array<string, array{reason: string, decidedOn: string}>
     */
    public const ENTRIES = [
        'openai/gpt-6-sol-pro' => [
            'reason' => "OpenRouter-only variant: OpenAI's own GET /v1/models does not serve this id (checked 2026-09-24).",
            'decidedOn' => '2026-09-24',
        ],
        'openai/gpt-6-luna-pro' => [
            'reason' => "OpenRouter-only variant: OpenAI's own GET /v1/models does not serve this id (checked 2026-09-24).",
            'decidedOn' => '2026-09-24',
        ],
        'openai/gpt-6-astra-pro' => [
            'reason' => "OpenRouter-only variant: OpenAI's own GET /v1/models does not serve this id (checked 2026-09-24).",
            'decidedOn' => '2026-09-24',
        ],
    ];
}
