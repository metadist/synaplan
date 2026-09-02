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
     * The AI sorter (`DEFAULTMODEL.SORT`) decided — including its internal
     * rule-based shortcut ({@see \App\Service\Message\MessageSorter::checkRuleBasedRouting()})
     * and its parse-failure fallback, both of which keep emitting this same
     * `source` value for backward compatibility. {@see RoutingDecision::$confidence}
     * and {@see RoutingDecision::$fallbackReason} are what actually
     * distinguish a genuine classification from a fallback within this layer.
     */
    case AiSorting = 'ai_sorting';
}
