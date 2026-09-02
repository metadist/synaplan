<?php

declare(strict_types=1);

namespace App\Service\Message\Capability;

/**
 * Typed declaration of ONE system-level routing capability.
 *
 * A capability is what today lives split across four hand-maintained,
 * silently-driftable places: the topic seeded in `BPROMPTS`
 * ({@see \App\Prompt\PromptCatalog}), the topic→intent entry in
 * {@see \App\Service\Message\MessageClassifier::mapTopicToIntent()}, the
 * intent→handler entry in {@see \App\Service\Message\InferenceRouter}, and —
 * for `mediamaker` — the BMEDIA/BINPUTMODE/BRESOLUTION enums duplicated in
 * {@see \App\AI\StructuredOutput\Schema\SortClassificationSchema}. One of
 * these had already drifted: `document_generation` existed as an intent but
 * had no handler entry, silently defaulting to `chat` — which happened to be
 * correct, but only by accident (`ChatHandler` runs the officemaker
 * structured-output path internally).
 *
 * `handlerName` being a required, non-nullable constructor argument is the
 * point: a capability WITHOUT a handler can no longer be declared at all —
 * see {@see SystemCapabilityRegistry} for the corrected `document_generation`
 * declaration.
 *
 * Covers only the four SYSTEM topics (general, mediamaker, officemaker,
 * docsummary) seeded by {@see \App\Prompt\PromptCatalog}. User-authored
 * custom topics from `BPROMPTS` (BOWNERID > 0) remain a second, dynamic
 * source appended at runtime via
 * {@see \App\Repository\PromptRepository::getAllTopics()} — this registry
 * describes system capabilities, it does not replace that mechanism.
 */
final readonly class SystemCapability
{
    /**
     * @param list<string>                          $exampleUtterances Curated example phrasings for this
     *                                                                 capability. Not consumed yet — reserved for
     *                                                                 the embedding-router anchors (Phase 8) so
     *                                                                 those anchors are declared alongside the
     *                                                                 capability instead of a fifth hand-maintained
     *                                                                 list.
     * @param array<string, list<string|null>>|null $parameterSchema   Enum-constrained parameters this
     *                                                                 capability accepts (e.g. mediamaker's
     *                                                                 media_type/input_mode/resolution), or null
     *                                                                 when the capability takes no structured
     *                                                                 parameters. Feeds
     *                                                                 {@see \App\AI\StructuredOutput\Schema\SortClassificationSchema}
     *                                                                 so those enums are declared once, here.
     */
    public function __construct(
        public string $topic,
        public string $intent,
        public string $handlerName,
        public string $description,
        public array $exampleUtterances = [],
        public ?array $parameterSchema = null,
    ) {
    }
}
