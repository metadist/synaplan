<?php

declare(strict_types=1);

namespace App\Service\Message\Capability;

/**
 * Single source of truth for the four SYSTEM routing capabilities
 * (`general`, `mediamaker`, `officemaker`, `docsummary`).
 *
 * Replaces four independently hand-maintained mappings that had to stay in
 * sync by convention alone:
 *  - topic → intent ({@see \App\Service\Message\MessageClassifier::mapTopicToIntent()})
 *  - intent → handler ({@see \App\Service\Message\InferenceRouter})
 *  - the BMEDIA/BINPUTMODE/BRESOLUTION enums duplicated in
 *    {@see \App\AI\StructuredOutput\Schema\SortClassificationSchema} and
 *    {@see \App\Service\Message\MessageSorter}
 *  - the eval corpus categories ({@see \App\Command\SortEvalCommand}'s
 *    `tests/Eval/sort_eval_corpus.json`)
 *
 * `document_generation` is the case that had already drifted before this
 * registry existed: the intent existed but {@see \App\Service\Message\InferenceRouter}
 * had no entry for it, silently defaulting to `chat` via `?? 'chat'`. That
 * default happened to be correct — `ChatHandler` runs the officemaker
 * structured-output path internally — but nothing enforced it. Declaring
 * `handlerName` as a required constructor argument on {@see SystemCapability}
 * makes that impossible to omit again; {@see self::byTopic()} for
 * `officemaker` is the regression test for the closed gap.
 *
 * A stateless registry (no constructor dependencies), so it is cheap to
 * construct directly in tests instead of mocking.
 */
final class SystemCapabilityRegistry
{
    /**
     * Canonical media-generation parameter enums. Public so
     * {@see \App\AI\StructuredOutput\Schema\SortClassificationSchema} and
     * {@see \App\Service\Message\MessageSorter} can reference them as
     * compile-time class-constant expressions (`self::X = SystemCapabilityRegistry::Y`)
     * without needing this registry injected as a runtime dependency.
     */
    public const MEDIAMAKER_MEDIA_TYPES = ['image', 'video', 'audio'];
    public const MEDIAMAKER_INPUT_MODES = ['text_only', 'reference_images'];
    public const MEDIAMAKER_VIDEO_RESOLUTIONS = ['720p', '1080p', '4K'];

    /** @var list<SystemCapability> */
    private array $capabilities;

    public function __construct()
    {
        $this->capabilities = [
            new SystemCapability(
                topic: 'general',
                intent: 'chat',
                handlerName: 'chat',
                description: 'Plain conversational answer with no specialised output format.',
                exampleUtterances: [
                    'Hello, how are you?',
                    'What is the capital of France?',
                    'Tell me a joke',
                ],
            ),
            new SystemCapability(
                topic: 'mediamaker',
                intent: 'image_generation',
                handlerName: 'image_generation',
                description: 'Generate or edit an image, video, or audio clip.',
                exampleUtterances: [
                    'Make an image of a cat',
                    'Create an 8 second video of the alps',
                    'Read this text aloud as an MP3',
                ],
                parameterSchema: [
                    'media_type' => self::MEDIAMAKER_MEDIA_TYPES,
                    'input_mode' => self::MEDIAMAKER_INPUT_MODES,
                    'resolution' => self::MEDIAMAKER_VIDEO_RESOLUTIONS,
                ],
            ),
            new SystemCapability(
                topic: 'officemaker',
                // ChatHandler runs the officemaker structured-output path
                // internally (topic-based, not intent-based) — this is the
                // explicit declaration that replaces the accidental
                // `?? 'chat'` default in InferenceRouter::getHandler().
                intent: 'document_generation',
                handlerName: 'chat',
                description: 'Generate an office document (Word, Excel, PowerPoint, or CSV).',
                exampleUtterances: [
                    'Create an Excel table with 10 programming languages and their release year',
                    'Write a marketing plan document with a table of contents',
                ],
            ),
            new SystemCapability(
                topic: 'docsummary',
                intent: 'chat',
                handlerName: 'chat',
                description: 'Summarize an attached or referenced document.',
                exampleUtterances: [
                    'Summarize this document',
                    'Give me the key points of this PDF',
                ],
            ),
        ];
    }

    /**
     * @return list<SystemCapability>
     */
    public function all(): array
    {
        return $this->capabilities;
    }

    public function byTopic(string $topic): ?SystemCapability
    {
        foreach ($this->capabilities as $capability) {
            if ($capability->topic === $topic) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function topics(): array
    {
        return array_map(static fn (SystemCapability $c): string => $c->topic, $this->capabilities);
    }

    /**
     * @return array<string, string> topic => intent
     */
    public function topicToIntentMap(): array
    {
        $map = [];
        foreach ($this->capabilities as $capability) {
            $map[$capability->topic] = $capability->intent;
        }

        return $map;
    }

    /**
     * @return array<string, string> intent => handler name (as registered under the
     *                               `app.message.handler` DI tag, see {@see \App\Service\Message\InferenceRouter})
     */
    public function intentToHandlerMap(): array
    {
        $map = [];
        foreach ($this->capabilities as $capability) {
            $map[$capability->intent] = $capability->handlerName;
        }

        return $map;
    }
}
