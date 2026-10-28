<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Billable AI actions recorded in `BUSELOG` (`03_contracts.md` §1.5).
 *
 * Non-AI actions (`TOOLS`, `MCP_TOOL`, `COMPUTE_RUNS`, `WEB_SEARCH`,
 * `FILE_ANALYSIS`, `FILE_UPLOADS`) are not part of this enum and move with S4.
 */
enum AiAction: string
{
    case AiCall = 'AI_CALL';
    case Messages = 'MESSAGES';
    case Sorting = 'SORTING';
    case MemoryExtraction = 'MEMORY_EXTRACTION';
    case MemoryParse = 'MEMORY_PARSE';
    case Planning = 'PLANNING';
    case SearchQuery = 'SEARCH_QUERY';
    case Embeddings = 'EMBEDDINGS';
    case Rerank = 'RERANK';
    case Images = 'IMAGES';
    case Videos = 'VIDEOS';
    case Audios = 'AUDIOS';
    case Transcription = 'TRANSCRIPTION';
    case VoiceLive = 'VOICE_LIVE';
    case FileSummary = 'FILE_SUMMARY';
    case DocSummary = 'DOC_SUMMARY';
    case MessageDigest = 'MESSAGE_DIGEST';
    case Condense = 'CONDENSE';
    case ChatTitle = 'CHAT_TITLE';
    case WidgetSetup = 'WIDGET_SETUP';
    case WidgetSummary = 'WIDGET_SUMMARY';
    case WidgetTitle = 'WIDGET_TITLE';
    case EmailRouting = 'EMAIL_ROUTING';
    case Feedback = 'FEEDBACK';

    /**
     * Accounting unit of this action (`03_contracts.md` §1.5):
     * - `per_turn`: one `BUSELOG` row per turn (`MESSAGES` on every channel) or session (`VOICE_LIVE`)
     * - `per_call`: one `BUSELOG` row per provider call
     *
     * @return 'per_turn'|'per_call'
     */
    public function accountingUnit(): string
    {
        return match ($this) {
            self::Messages, self::VoiceLive => 'per_turn',
            default => 'per_call',
        };
    }

    public function isPerTurn(): bool
    {
        return 'per_turn' === $this->accountingUnit();
    }

    public static function isAiAction(string $action): bool
    {
        return null !== self::tryFrom($action);
    }
}
