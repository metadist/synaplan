<?php

declare(strict_types=1);

namespace App\Service\Message\Routing;

/**
 * The deciding layer of a routing decision.
 *
 * Backed by the exact `source` strings the classification pipeline has
 * always emitted (see {@see \App\Service\Message\MessageClassifier::classify()}
 * and {@see \App\Service\Message\MessageSorter::classify()}), so
 * {@see RoutingDecision::toClassificationSource()} never changes the
 * `$classification['source']` contract that
 * {@see \App\Service\Multitask\TaskPlanExecutor} gates on
 * (`in_array($source, ['ai_sorting', 'attachment_document_or_audio', 'saved_task'])`).
 * This enum documents the cascade order, it does not change it.
 */
enum RoutingLayer: string
{
    /** Phase 1c local regex/keyword heuristic — no AI call, default OFF. */
    case FastPathHeuristic = 'fast_path_heuristic';

    /** "Again" with an explicit model but no prompt override. */
    case ModelOverride = 'model_override_auto';

    /** "Again" with a specific prompt/topic re-selected by the user. */
    case PromptOverride = 'prompt_override';

    /** Slash command (/pic, /vid, /tts, /search, /lang, /web, /list, /docs). */
    case ToolCommand = 'tool_command';

    /** Document/audio/video attachment forced to `analyzefile`. */
    case AttachmentRule = 'attachment_document_or_audio';

    /**
     * Phase 8: cosine-similarity match against pre-computed example-utterance
     * anchors for the four SYSTEM topics (general, mediamaker, officemaker,
     * docsummary), embedded with bge-m3 and stored in Qdrant — see
     * {@see EmbeddingRouterService}. Sits after
     * every deterministic layer above and before the AI sorter below; a
     * confident match skips the sorter round-trip the same way
     * {@see FastPathHeuristic} does, generalised from regex matching to
     * semantic matching and from one topic (`general`) to all four. Like
     * {@see FastPathHeuristic}, this source is deliberately absent from
     * {@see \App\Service\Multitask\TaskPlanExecutor}'s planner allow-list: a
     * bypassed sorter has no `multi_step` vote to plan on, so the DAG
     * planner is skipped too and the legacy single-node router handles the
     * turn — identical trade-off to the fast-path, not a new one.
     */
    case EmbeddingRouter = 'embedding_router';

    /**
     * The AI sorter (`DEFAULTMODEL.SORT`) decided — including its internal
     * rule-based shortcut ({@see \App\Service\Message\MessageSorter::checkRuleBasedRouting()})
     * and its parse-failure fallback, both of which keep emitting this same
     * `source` value for backward compatibility. {@see RoutingDecision::$confidence}
     * and {@see RoutingDecision::$fallbackReason} are what actually
     * distinguish a genuine classification from a fallback within this layer.
     */
    case AiSorting = 'ai_sorting';
}
