<?php

declare(strict_types=1);

namespace App\Service\Multitask\Plan;

/**
 * The v1 task-plan capability vocabulary (see planning doc §3.2).
 *
 * DESIGN RULE: every capability maps to code that ALREADY runs in production —
 * v1 adds no new model integrations or generation services. A capability is a
 * thin adapter over an existing handler/service. This keeps the blast radius
 * small and protects every existing tool.
 *
 * The capability is what the planner DECIDES. The model that runs it is resolved
 * later by the existing capability → PromptMeta.aiModel → DEFAULTMODEL chain
 * (the migration principle: the planner never picks models).
 */
enum Capability: string
{
    /** Tika/Whisper text extraction from attached files (runs in MessagePreProcessor). */
    case ExtractText = 'extract_text';

    /** Plain chat answer (ChatHandler). Carries an optional topic/prompt id for custom user topics. */
    case Chat = 'chat';

    /** Summarization (ChatHandler with summary prompt). */
    case Summarize = 'summarize';

    /** Translation (ChatHandler). */
    case Translate = 'translate';

    /** Retrieval-augmented answer (ChatHandler + VectorSearchService). */
    case RagQuery = 'rag_query';

    /** Web search (BraveSearchService + SearchQueryGenerator). */
    case WebSearch = 'web_search';

    /** Fetch + read the content of specific URL(s) named in the request (UrlContentService). */
    case UrlFetch = 'url_fetch';

    /** Pull data from one of the user's connected external MCP servers (McpClient, read-only v1). */
    case McpFetch = 'mcp_fetch';

    /** Live read-only IMAP search over the user's connected mailboxes (InboundEmailHandler accounts). */
    case EmailSearch = 'email_search';

    /** Vision / OCR / document Q&A (FileAnalysisHandler). */
    case FileAnalysis = 'file_analysis';

    /** Image generation / edit (MediaGenerationHandler). */
    case ImageGeneration = 'image_generation';

    /** Video generation (MediaGenerationHandler). */
    case VideoGeneration = 'video_generation';

    /** Text-to-speech (AiFacade::synthesize). */
    case Text2Sound = 'text2sound';

    /** Office document generation (ChatHandler officemaker + DocumentGeneratorService). */
    case DocumentGeneration = 'document_generation';

    /** Calendar event / meeting invite as a downloadable .ics file (CalendarEventService, no model). */
    case CalendarEvent = 'calendar_event';

    /** Mail the assembled results to the account owner as one multi-MIME email (InternalEmailService, no model). */
    case EmailMe = 'email_me';

    /** Final assembly of text + N file attachments into one OUT message (ResultAssembler, no model). */
    case ComposeReply = 'compose_reply';

    /**
     * @return list<string> all capability string values
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * The UI card kind for this capability (drives the frontend task-card body).
     * `compose_reply` returns 'hidden' — it is the assembler, not a visible card.
     */
    public function uiKind(): string
    {
        return match ($this) {
            self::ExtractText => 'extract',
            self::Chat, self::Summarize, self::Translate, self::RagQuery, self::FileAnalysis => 'text',
            self::WebSearch, self::UrlFetch, self::McpFetch, self::EmailSearch => 'search',
            self::ImageGeneration => 'image',
            self::VideoGeneration => 'video',
            self::Text2Sound => 'audio',
            self::DocumentGeneration => 'document',
            self::CalendarEvent => 'document',
            self::EmailMe => 'email',
            self::ComposeReply => 'hidden',
        };
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
