<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `tool_use` to BJSON.features for chat models whose upstream documents
 * function calling. Idempotent: appends only when the flag is absent. Does
 * not rewrite operator-owned toggles (BSELECTABLE, BACTIVE, BISDEFAULT) or
 * any other BJSON keys. Never removes an operator-added feature.
 *
 * Galera-safe: raw DML only, no Schema API (AGENTS.md).
 */
final class Version20260902120000 extends AbstractMigration
{
    /**
     * service + providerId pairs matching ModelCatalog chat rows that must
     * carry tool_use. Keep in sync with CatalogToolUse::CAPABLE_CHAT_SERVICES.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const TARGETS = [
        ['OpenAI', 'gpt-4o-mini'],
        ['OpenAI', 'gpt-5.4'],
        ['OpenAI', 'gpt-5.5'],
        ['OpenAI', 'gpt-5.5-pro'],
        ['OpenAI', 'gpt-5.6-sol'],
        ['OpenAI', 'gpt-5.6-terra'],
        ['OpenAI', 'gpt-5.6-luna'],
        ['OpenAI', 'gpt-5.4-mini'],
        ['OpenAI', 'gpt-5.4-nano'],
        ['Groq', 'qwen/qwen3.6-27b'],
        ['Groq', 'openai/gpt-oss-20b'],
        ['Groq', 'openai/gpt-oss-120b'],
        ['Anthropic', 'claude-haiku-4-5-20251001'],
        ['Anthropic', 'claude-opus-4-8'],
        ['Anthropic', 'claude-fable-5'],
        ['Anthropic', 'claude-sonnet-5'],
        ['Anthropic', 'claude-opus-5'],
        ['Anthropic', 'claude-fable-5-1'],
        ['Google', 'gemini-2.5-pro'],
        ['Google', 'gemini-2.5-flash'],
        ['Google', 'gemini-3.1-pro-preview'],
        ['Google', 'gemini-3.1-flash-lite'],
        ['Google', 'gemini-3.5-flash'],
        ['Google', 'gemini-3-flash-preview'],
        ['Google', 'gemini-2.5-flash-lite'],
        ['Mistral', 'mistral-medium-latest'],
        ['Mistral', 'mistral-large-latest'],
        ['xAI', 'grok-4.6'],
        ['xAI', 'grok-4.5'],
        ['HuggingFace', 'moonshotai/Kimi-K2.5:deepinfra'],
        ['HuggingFace', 'moonshotai/Kimi-K2.6:deepinfra'],
        ['HuggingFace', 'moonshotai/Kimi-K2.7-Code:deepinfra'],
        ['HuggingFace', 'moonshotai/Kimi-K3:deepinfra'],
        ['TrustedTokens', 'zai-org/GLM-5.2'],
        ['TrustedTokens', 'Qwen/Qwen3.6-35B-A3B-FP8'],
        ['TrustedTokens', 'openai/gpt-oss-120b'],
        ['TrustedTokens', 'zai-org/GLM-5.3'],
        ['TrustedTokens', 'zai-org/GLM-5.3-Flash'],
        ['TrustedTokens', 'tngtech/DeepSeek-TNG-R1T2-Chimera'],
        ['TrustedTokens', 'deepseek-ai/DeepSeek-V4-Flash'],
        ['TrustedTokens', 'deepseek-ai/DeepSeek-V4-Flash-0731'],
        ['TrustedTokens', 'deepseek-ai/DeepSeek-V4-Pro-0813'],
    ];

    public function getDescription(): string
    {
        return 'Append tool_use to BJSON.features for capable chat models (dual tool-calling gate)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::TARGETS as [$service, $provid]) {
            $this->addSql(
                <<<'SQL'
                    UPDATE BMODELS
                       SET BJSON = CASE
                            WHEN JSON_EXTRACT(COALESCE(BJSON, '{}'), '$.features') IS NULL
                              OR JSON_TYPE(JSON_EXTRACT(COALESCE(BJSON, '{}'), '$.features')) <> 'ARRAY'
                            THEN JSON_SET(COALESCE(BJSON, '{}'), '$.features', JSON_ARRAY('tool_use'))
                            WHEN JSON_CONTAINS(JSON_EXTRACT(BJSON, '$.features'), JSON_QUOTE('tool_use'), '$')
                            THEN BJSON
                            ELSE JSON_ARRAY_APPEND(BJSON, '$.features', 'tool_use')
                       END
                     WHERE BSERVICE = ?
                       AND BPROVID = ?
                       AND BTAG = 'chat'
                    SQL,
                [$service, $provid]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Feature flags are additive. Rolling this back would also strip an
        // operator-added `tool_use` on the same rows, which the catalog
        // contract forbids. Leave BJSON unchanged.
    }
}
