<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Message\ConversationSummaryConstants;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\Tool\ToolsConfig;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Routing tab: multi-task routing, Saved Tasks, the tool policies, the
 * rolling conversation summary and deep memory (message digests). BCONFIG
 * rows, no restart.
 */
#[AsTaggedItem(priority: 40)]
final readonly class RoutingTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'routing';
    }

    public function label(): string
    {
        return 'Routing';
    }

    public function sections(): array
    {
        return [
            'multitask' => $this->multitask(),
            'saved_tasks' => $this->savedTasks(),
            'tools' => $this->tools(),
            'conversation_summary' => $this->conversationSummary(),
            'deep_memory' => $this->deepMemory(),
        ];
    }

    /**
     * Stored in BCONFIG group MULTITASK / setting ROUTING_ENABLED (the row
     * MultitaskRoutingConfig reads), not the default QDRANT_SEARCH group.
     */
    private function multitask(): ConfigSection
    {
        return new ConfigSection('Multi-task routing', [
            'MULTITASK_ROUTING_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Route messages through the multi-task planner (turns a request into a small DAG of capability tasks). When OFF, the legacy single-topic AI sorter handles routing. Global default for all users; an explicit per-user BCONFIG row still overrides it.',
                default: 'true',
                row: new DatabaseRow(MultitaskRoutingConfig::CONFIG_GROUP, MultitaskRoutingConfig::KEY_ROUTING_ENABLED),
            ),
        ]);
    }

    private function savedTasks(): ConfigSection
    {
        return new ConfigSection('Saved Tasks', [
            'SAVEDTASKS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Allow users to pin a Task Prompt as a Saved Task and run it on demand or on a schedule. When OFF, AI Instructions stay unchanged and no Saved Task APIs or UI are exposed. Per-user BCONFIG row overrides the global row; code default is OFF when no row exists.',
                default: 'true',
                row: new DatabaseRow(SavedTaskConfig::CONFIG_GROUP, SavedTaskConfig::KEY_ENABLED),
            ),
        ]);
    }

    private function tools(): ConfigSection
    {
        return new ConfigSection('Tool policies', [
            'TOOLS_POLICY_READ' => new FieldDefinition(
                type: 'select',
                description: 'Default for tools that only read data: auto, approve or block.',
                default: 'auto',
                row: new DatabaseRow(ToolsConfig::CONFIG_GROUP, ToolsConfig::KEY_POLICY_READ),
                options: ['auto', 'approve', 'block'],
            ),
            'TOOLS_POLICY_WRITE' => new FieldDefinition(
                type: 'select',
                description: 'Default for tools that change something: auto, approve or block.',
                default: 'approve',
                row: new DatabaseRow(ToolsConfig::CONFIG_GROUP, ToolsConfig::KEY_POLICY_WRITE),
                options: ['auto', 'approve', 'block'],
            ),
            'TOOLS_POLICY_DESTRUCTIVE' => new FieldDefinition(
                type: 'select',
                description: 'Default for tools that delete something: auto, approve or block.',
                default: 'block',
                row: new DatabaseRow(ToolsConfig::CONFIG_GROUP, ToolsConfig::KEY_POLICY_DESTRUCTIVE),
                options: ['auto', 'approve', 'block'],
            ),
            'TOOLS_APPROVAL_EXPIRY_HOURS' => new FieldDefinition(
                type: 'number',
                description: 'Hours a pending approval waits before it expires (1–720). Default 72.',
                default: '72',
                row: new DatabaseRow(ToolsConfig::CONFIG_GROUP, ToolsConfig::KEY_APPROVAL_EXPIRY_HOURS),
            ),
        ]);
    }

    /**
     * BCONFIG group CONVERSATION_SUMMARY (owner 0), the rows
     * ConversationSummaryConfigService reads. No row means "use the
     * ConversationSummaryConstants default", so these defaults must stay in
     * sync with that class.
     */
    private function conversationSummary(): ConfigSection
    {
        return new ConfigSection('Rolling conversation summary', [
            'CONVERSATION_SUMMARY_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Condense the earlier part of a long chat into a rolling summary that is injected into the system prompt, while the newest turns are still replayed word for word. Keeps the topic, the user\'s position and past decisions alive many answers later. The summary is written asynchronously after each turn by the Summary model (configurable under AI → Routing), so answering stays snappy. When OFF, a long chat only ever sees the most recent turns.',
                default: var_export(ConversationSummaryConstants::ENABLED, true),
                row: new DatabaseRow(ConversationSummaryConstants::CONFIG_GROUP, ConversationSummaryConstants::KEY_ENABLED),
            ),
            'CONVERSATION_SUMMARY_TARGET_WINDOW_CHARS' => new FieldDefinition(
                type: 'number',
                description: 'Total characters of conversational memory sent to the answering model: the verbatim recent turns plus the injected summary. Clamped to '.ConversationSummaryConstants::MIN_WINDOW_CHARS.'–'.ConversationSummaryConstants::MAX_WINDOW_CHARS.'.',
                default: (string) ConversationSummaryConstants::TARGET_WINDOW_CHARS,
                row: new DatabaseRow(ConversationSummaryConstants::CONFIG_GROUP, ConversationSummaryConstants::KEY_TARGET_WINDOW_CHARS),
            ),
            'CONVERSATION_SUMMARY_RECENT_VERBATIM_CHARS' => new FieldDefinition(
                type: 'number',
                description: 'Share of the window reserved for the newest turns, which are replayed word for word. Everything older is condensed. Raise it to keep more literal context, lower it to summarize sooner. Always leaves at least 500 characters for the summary.',
                default: (string) ConversationSummaryConstants::RECENT_VERBATIM_CHARS,
                row: new DatabaseRow(ConversationSummaryConstants::CONFIG_GROUP, ConversationSummaryConstants::KEY_RECENT_VERBATIM_CHARS),
            ),
            'CONVERSATION_SUMMARY_MAX_CHARS' => new FieldDefinition(
                type: 'number',
                description: 'Hard cap on the injected summary itself. Capped at whatever the window has left after the verbatim turns.',
                default: (string) ConversationSummaryConstants::SUMMARY_MAX_CHARS,
                row: new DatabaseRow(ConversationSummaryConstants::CONFIG_GROUP, ConversationSummaryConstants::KEY_SUMMARY_MAX_CHARS),
            ),
            'CONVERSATION_SUMMARY_MAX_SOURCE_MESSAGES' => new FieldDefinition(
                type: 'number',
                description: 'Upper bound on how many older messages are fed to the Summary model in one call. Bounds the cost of summarizing a very long conversation; older messages beyond this are represented by the previous summary.',
                default: (string) ConversationSummaryConstants::MAX_SOURCE_MESSAGES,
                row: new DatabaseRow(ConversationSummaryConstants::CONFIG_GROUP, ConversationSummaryConstants::KEY_MAX_SOURCE_MESSAGES),
            ),
            'CONVERSATION_SUMMARY_TIERS' => new FieldDefinition(
                type: 'number',
                description: 'Number of recency tiers used for gradient compression: the oldest tier is condensed hardest, the tier next to the verbatim turns the least. 1–5.',
                default: (string) ConversationSummaryConstants::TIERS,
                row: new DatabaseRow(ConversationSummaryConstants::CONFIG_GROUP, ConversationSummaryConstants::KEY_TIERS),
            ),
            'CONVERSATION_SUMMARY_CACHE_TTL' => new FieldDefinition(
                type: 'number',
                description: 'Seconds the stored summary is kept before Redis expires it. The summary is refreshed asynchronously after each long-chat turn, so a follow-up turn never waits on the summarizer. Raise this on quiet installs; lower it only if you need settings changes to take effect faster.',
                default: (string) ConversationSummaryConstants::CACHE_TTL,
                row: new DatabaseRow(ConversationSummaryConstants::CONFIG_GROUP, ConversationSummaryConstants::KEY_CACHE_TTL),
            ),
        ]);
    }

    /**
     * BCONFIG group DIGEST (owner 0), the rows MessageDigestConfig reads. No
     * row means "use the MessageDigestConfig default", so these defaults must
     * stay in sync with that class. The per-user scan cursor lives in the
     * same group under the user's own id and is never exposed here.
     */
    private function deepMemory(): ConfigSection
    {
        return new ConfigSection('Deep memory (message digests)', [
            'DIGEST_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Deep memory master switch. A daily job condenses each user\'s KEY messages (documents, decisions, important facts) into one-line digests indexed in the vector DB; a chat prompt months later can then find and quote the original message ("the office rent letter from May"). Controls both the daily indexing job and the retrieval during chat. When OFF, older conversations are only reachable through extracted memories.',
                default: var_export(MessageDigestConfig::DEFAULT_ENABLED, true),
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_ENABLED),
            ),
            'DIGEST_TOP_K' => new FieldDefinition(
                type: 'number',
                description: 'Maximum digest hits considered per chat turn (after re-ranking by similarity and recency). 1–20.',
                default: (string) MessageDigestConfig::DEFAULT_TOP_K,
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_TOP_K),
            ),
            'DIGEST_MIN_SCORE' => new FieldDefinition(
                type: 'number',
                description: 'Vector similarity floor (0.0–1.0) a digest must clear to be considered at all. Raise it to cut noise, lower it to increase recall. Tuned with app:digest:eval.',
                default: (string) MessageDigestConfig::DEFAULT_MIN_SCORE,
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_MIN_SCORE),
            ),
            'DIGEST_RECENCY_HALF_LIFE_DAYS' => new FieldDefinition(
                type: 'number',
                description: 'Half-life of the recency decay in days: at this age a digest\'s effective score is halved. Deliberately slow, so an old but highly relevant message still beats a recent vague one.',
                default: (string) MessageDigestConfig::DEFAULT_RECENCY_HALF_LIFE_DAYS,
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_RECENCY_HALF_LIFE_DAYS),
            ),
            'DIGEST_PULL_TOP_N' => new FieldDefinition(
                type: 'number',
                description: 'How many of the best hits get their ORIGINAL message pulled verbatim into the prompt (so the model can quote amounts and dates, not just know the message exists). 0 disables pulling; the digest lines still appear.',
                default: (string) MessageDigestConfig::DEFAULT_PULL_TOP_N,
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_PULL_TOP_N),
            ),
            'DIGEST_PULL_MIN_SCORE' => new FieldDefinition(
                type: 'number',
                description: 'Raw similarity (0.0–1.0) a hit must clear before its source message is pulled verbatim. Higher than the search floor: pulling costs prompt space.',
                default: (string) MessageDigestConfig::DEFAULT_PULL_MIN_SCORE,
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_PULL_MIN_SCORE),
            ),
            'DIGEST_BLOCK_MAX_CHARS' => new FieldDefinition(
                type: 'number',
                description: 'Hard character cap for the whole "Older conversations" block injected into the system prompt (digest lines plus pulled excerpts).',
                default: (string) MessageDigestConfig::DEFAULT_BLOCK_MAX_CHARS,
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_BLOCK_MAX_CHARS),
            ),
            'DIGEST_BATCH_SIZE' => new FieldDefinition(
                type: 'number',
                description: 'Messages handed to the digest model per call during the daily indexing job. 5–100.',
                default: (string) MessageDigestConfig::DEFAULT_BATCH_SIZE,
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_BATCH_SIZE),
            ),
            'DIGEST_MAX_BATCHES_PER_USER' => new FieldDefinition(
                type: 'number',
                description: 'Cost cap: maximum digest-model calls per user per daily run. Unprocessed history is picked up by the next run (the per-user cursor never loses its place).',
                default: (string) MessageDigestConfig::DEFAULT_MAX_BATCHES_PER_USER,
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_MAX_BATCHES_PER_USER),
            ),
            'DIGEST_QUIET_SECONDS' => new FieldDefinition(
                type: 'number',
                description: 'Messages younger than this many seconds are left for a later run — the rolling summary covers the live conversation; the digest is the long-term index and must not race a chat still in progress.',
                default: (string) MessageDigestConfig::DEFAULT_QUIET_SECONDS,
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_QUIET_SECONDS),
            ),
            'DIGEST_MAX_PER_USER' => new FieldDefinition(
                type: 'number',
                description: 'Per-user cap on active digest entries (the deep-memory sibling of the 500-memory limit). On overflow the oldest entries are deactivated first. Minimum 100.',
                default: (string) MessageDigestConfig::DEFAULT_MAX_PER_USER,
                row: new DatabaseRow(MessageDigestConfig::CONFIG_GROUP, MessageDigestConfig::KEY_MAX_PER_USER),
            ),
        ]);
    }
}
