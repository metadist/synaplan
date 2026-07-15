<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\AI\Service\AiFacade;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\TtsTextSanitizer;
use Psr\Log\LoggerInterface;

/**
 * `text2sound` (TTS) runner — synthesizes speech from the upstream text via
 * AiFacade::synthesize, which resolves the TEXT2SOUND model itself and saves the
 * audio to the user's upload path. Produces an audio file descriptor.
 */
final readonly class Text2SoundRunner implements TaskRunner
{
    public function __construct(
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::Text2Sound];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(Capability::Text2Sound, 'Synthesize speech/audio from text (params.format, e.g. mp3).'),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $inputs = $context->resolveInputs($node);
        $text = $inputs['text'] ?? null;
        if (is_array($text)) {
            $text = implode("\n\n", array_filter($text, 'is_string'));
        }
        $text = is_string($text) ? $text : (string) $context->message->getText();

        // Strip markdown, <think> blocks, [Memory:ID] badges and other
        // non-speakable artifacts before TTS (issue #1164) — same treatment
        // TtsController and StreamController already apply before synthesis.
        $text = TtsTextSanitizer::sanitize($text);

        if ('' === trim($text)) {
            return NodeResult::failed('no text to synthesize');
        }

        $language = is_string($context->classification['language'] ?? null)
            ? $context->classification['language']
            : ($context->message->getLanguage() ?: 'en');

        try {
            $result = $this->aiFacade->synthesize($text, $context->userId, [
                'format' => is_string($node->params['format'] ?? null) ? $node->params['format'] : 'mp3',
                'language' => $language,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Text2SoundRunner: synthesize failed', ['error' => $e->getMessage()]);

            return NodeResult::failed('audio synthesis failed: '.$e->getMessage());
        }

        $relativePath = $result['relativePath'] ?? null;
        if (!is_string($relativePath) || '' === $relativePath) {
            return NodeResult::failed('synthesizer returned no file path');
        }

        $file = [
            'path' => '/api/v1/files/uploads/'.$relativePath,
            'type' => 'audio',
            'local_path' => $relativePath,
            // #1251: carried through ResultAssembler → persistTaskPlanFiles so
            // GeneratedFileRegistrar can store the spoken script as BFILETEXT.
            'source_text' => $text,
        ];

        return NodeResult::ok(null, [$file], [
            'provider' => $result['provider'] ?? null,
            'model' => $result['model'] ?? null,
            'media_type' => 'audio',
        ]);
    }
}
