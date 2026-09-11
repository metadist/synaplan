<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Register A2Agent catalog models (BIDs 361–366) on existing installs.
 *
 * ModelSeeder inserts new BIDs on deploy, but only when the row is absent.
 * This migration inserts the same catalog snapshot so existing installs get
 * the rows even if seed is skipped. Operator flags are never updated
 * (INSERT ... WHERE NOT EXISTS). Raw SQL / parameterized writes only
 * (Galera-safe; no Schema API).
 *
 * Snapshot 2026-09-11 from https://a2agent.me/models (USD per 1M, public group).
 */
final class Version20260911180000 extends AbstractMigration
{
    /** @var list<int> */
    private const NEW_BIDS = [361, 362, 363, 364, 365, 366];

    public function getDescription(): string
    {
        return 'Insert A2Agent catalog models (Qwen3.8 MAX/Flash, DeepSeek V4 Pro/Flash, MiniMax M3)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $byId = [
            361 => [
                'id' => 361,
                'service' => 'A2Agent',
                'name' => 'Qwen3.8 MAX',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'qwen3.8-max',
                'priceIn' => 2.00,
                'inUnit' => 'per1M',
                'priceOut' => 6.00,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Qwen3.8 MAX via A2Agent — Alibaba flagship reasoning model with a 1M context window. Reasoning + tools. Routed through the A2Agent gateway to Alibaba Qwen (China).',
                    'max_tokens' => 32768,
                    'params' => ['model' => 'qwen3.8-max'],
                    'features' => ['reasoning', 'tool_use', 'code', 'multilingual'],
                    'meta' => [
                        'context_window' => '1000000',
                        'max_output' => '32768',
                        'host' => 'a2agent.me',
                        'upstream' => 'Alibaba Qwen',
                        'jurisdiction' => 'CN',
                    ],
                ],
            ],
            362 => [
                'id' => 362,
                'service' => 'A2Agent',
                'name' => 'DeepSeek V4 Pro',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'deepseek-v4-pro',
                'priceIn' => 0.435,
                'inUnit' => 'per1M',
                'priceOut' => 0.87,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'DeepSeek V4 Pro via A2Agent — flagship reasoning model with a 1M context window. Reasoning + tools. Routed through the A2Agent gateway to DeepSeek (China).',
                    'max_tokens' => 32768,
                    'params' => ['model' => 'deepseek-v4-pro'],
                    'features' => ['reasoning', 'tool_use', 'code', 'multilingual'],
                    'meta' => [
                        'context_window' => '1000000',
                        'max_output' => '32768',
                        'host' => 'a2agent.me',
                        'upstream' => 'DeepSeek',
                        'jurisdiction' => 'CN',
                    ],
                ],
            ],
            363 => [
                'id' => 363,
                'service' => 'A2Agent',
                'name' => 'DeepSeek V4 Flash',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'deepseek-v4-flash',
                'priceIn' => 0.14,
                'inUnit' => 'per1M',
                'priceOut' => 0.28,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'DeepSeek V4 Flash via A2Agent — cheapest 1M-context reasoning model on the gateway. Reasoning + tools. Routed through the A2Agent gateway to DeepSeek (China).',
                    'max_tokens' => 32768,
                    'params' => ['model' => 'deepseek-v4-flash'],
                    'features' => ['reasoning', 'tool_use', 'code', 'multilingual'],
                    'meta' => [
                        'context_window' => '1000000',
                        'max_output' => '32768',
                        'host' => 'a2agent.me',
                        'upstream' => 'DeepSeek',
                        'jurisdiction' => 'CN',
                    ],
                ],
            ],
            364 => [
                'id' => 364,
                'service' => 'A2Agent',
                'name' => 'MiniMax M3',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'MiniMax-M3',
                'priceIn' => 0.30,
                'inUnit' => 'per1M',
                'priceOut' => 1.20,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'MiniMax M3 via A2Agent — agent-tagged 1M-context model. Tools + reasoning. Routed through the A2Agent gateway to MiniMax (China).',
                    'max_tokens' => 32768,
                    'params' => ['model' => 'MiniMax-M3'],
                    'features' => ['tool_use', 'reasoning', 'code', 'multilingual'],
                    'meta' => [
                        'context_window' => '1000000',
                        'max_output' => '32768',
                        'host' => 'a2agent.me',
                        'upstream' => 'MiniMax',
                        'jurisdiction' => 'CN',
                    ],
                ],
            ],
            365 => [
                'id' => 365,
                'service' => 'A2Agent',
                'name' => 'Qwen3.8 Flash',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'qwen3.8-flash',
                'priceIn' => 0.15,
                'inUnit' => 'per1M',
                'priceOut' => 0.47,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Qwen3.8 Flash via A2Agent — fast vision-capable model with a 1M context window. Reasoning + tools. Routed through the A2Agent gateway to Alibaba Qwen (China).',
                    'max_tokens' => 32768,
                    'params' => ['model' => 'qwen3.8-flash'],
                    'features' => ['reasoning', 'tool_use', 'vision', 'multilingual'],
                    'meta' => [
                        'context_window' => '1000000',
                        'max_output' => '32768',
                        'host' => 'a2agent.me',
                        'upstream' => 'Alibaba Qwen',
                        'jurisdiction' => 'CN',
                    ],
                ],
            ],
            366 => [
                'id' => 366,
                'service' => 'A2Agent',
                'name' => 'Qwen3.8 Flash (Vision)',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'qwen3.8-flash',
                'priceIn' => 0.15,
                'inUnit' => 'per1M',
                'priceOut' => 0.47,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Qwen3.8 Flash via A2Agent — image understanding and OCR-style text extraction. Routed through the A2Agent gateway to Alibaba Qwen (China).',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => ['model' => 'qwen3.8-flash'],
                    'features' => ['vision', 'ocr', 'multilingual'],
                    'meta' => [
                        'host' => 'a2agent.me',
                        'upstream' => 'Alibaba Qwen',
                        'jurisdiction' => 'CN',
                    ],
                ],
            ],
        ];

        foreach (self::NEW_BIDS as $bid) {
            $this->abortIf(
                !isset($byId[$bid]),
                sprintf('ModelCatalog is missing BID %d — the migration cannot insert it.', $bid),
            );

            /** @var array<string, mixed> $row */
            $row = $byId[$bid];

            $exists = $this->connection->fetchOne('SELECT BID FROM BMODELS WHERE BID = ?', [$bid]);
            if (false !== $exists) {
                continue;
            }

            ModelCatalog::upsert($this->connection, $row);
        }
    }

    public function down(Schema $schema): void
    {
        // Rows stay: BMESSAGES / BUSELOG reference BIDs. Disable via the admin UI
        // or ModelCatalog::RETIREMENTS if a later release withdraws them.
    }
}
