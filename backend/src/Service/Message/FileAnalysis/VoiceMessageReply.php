<?php

declare(strict_types=1);

namespace App\Service\Message\FileAnalysis;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Replies to transcribed voice messages as to a typed chat turn, never as a
 * file to describe (#955). Several voice notes on one message are answered
 * together, every transcript included (#978).
 */
final readonly class VoiceMessageReply
{
    private const MAX_TOKENS = 4000;

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $audioFiles
     *
     * @return array<string, mixed>
     */
    public function reply(AnalysisContext $context, array $audioFiles): array
    {
        $context->notify('generating', 'Replying to voice message...');

        $prompts = $this->prompts($audioFiles, $context->message->getText());
        $prompts['system'] .= $context->languageDirective();

        $model = AnalysisModel::choose($this->modelConfigService, $context, 'CHAT');
        // The transcript length, not the system prompt: the "do NOT describe
        // the recording" boilerplate would overstate the user's content.
        $this->logger->info('FileAnalysisHandler: Replying to voice message', $model->logContext($context) + [
            'transcript_length' => strlen($this->joinTranscripts($audioFiles)),
            'voice_message_count' => count($audioFiles),
        ]);

        try {
            $response = $context->reply->chat(
                $this->aiFacade,
                [
                    ['role' => 'system', 'content' => $prompts['system']],
                    ['role' => 'user', 'content' => $prompts['prompt']],
                ],
                $context->message->getUserId(),
                $model->options(self::MAX_TOKENS),
            );
            $context->notify('complete', 'Reply complete.');

            return $context->reply->fromModel(
                $response,
                $model->metadata($response['provider'] ?? null, $response['model'] ?? null, $audioFiles, 'voice_message_reply'),
            );
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Voice message reply failed', [
                'files' => AnalysisFileResolver::describe($audioFiles),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param list<array<string, mixed>> $audioFiles
     *
     * @return array{system: string, prompt: string}
     */
    private function prompts(array $audioFiles, string $userPrompt): array
    {
        $transcript = $this->joinTranscripts($audioFiles);
        $cleanedUserPrompt = trim($userPrompt);

        $intro = count($audioFiles) > 1
            ? 'The user sent you '.count($audioFiles).' voice messages. The transcripts of what they actually said are provided below in order.'
            : 'The user sent you a voice message. The transcript of what they actually said is provided below.';

        $systemPrompt =
            $intro."\n\n"
            ."Respond directly and conversationally to the transcript, exactly as you would to a normal text chat message.\n\n"
            ."IMPORTANT — do NOT:\n"
            ."- describe, summarize, or analyze the audio file itself\n"
            ."- mention the file format (OGG, MP3, WAV, etc.) or that it is a recording\n"
            ."- say things like \"the audio file contains\", \"the recording says\", \"the user said in the audio\"\n"
            ."- quote the transcript back at the user unless they explicitly asked you to\n\n"
            ."Treat the transcript as if the user had typed it.\n\n"
            ."=== VOICE MESSAGE TRANSCRIPT ===\n"
            .$transcript."\n"
            .'=== END TRANSCRIPT ===';

        // Audio only: the transcript itself is the user turn. A typed
        // instruction beside the recording ("translate this") stays verbatim;
        // the system prompt already carries the transcript.
        $finalPrompt = $this->isPlaceholderPrompt($cleanedUserPrompt, $transcript) ? $transcript : $cleanedUserPrompt;

        return [
            'system' => $systemPrompt,
            'prompt' => '' !== $finalPrompt ? $finalPrompt : $transcript,
        ];
    }

    /**
     * Every transcript; several recordings are labelled so the model can
     * tell them apart, a single one keeps the label-free shape.
     *
     * @param list<array<string, mixed>> $audioFiles
     */
    private function joinTranscripts(array $audioFiles): string
    {
        if (1 === count($audioFiles)) {
            return trim((string) ($audioFiles[0]['text'] ?? ''));
        }

        $parts = [];
        foreach ($audioFiles as $index => $audio) {
            $transcript = trim((string) ($audio['text'] ?? ''));
            if ('' === $transcript) {
                continue;
            }
            $label = 'Voice message '.($index + 1);
            if (!empty($audio['name'])) {
                $label .= ' ('.$audio['name'].')';
            }
            $parts[] = "--- {$label} ---\n".$transcript;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Whether the user prompt is only what an audio-only upload carries,
     * judged by locale-agnostic signals instead of translated placeholders:
     * empty (web), identical to the transcript (mobile pre-fill, the
     * pre-processor's `[Audio]` handling), or a single bracketed marker such
     * as `[voice note]`. Anything else is a real instruction.
     */
    private function isPlaceholderPrompt(string $cleanedUserPrompt, string $transcript): bool
    {
        if ('' === $cleanedUserPrompt) {
            return true;
        }

        if (mb_strtolower($cleanedUserPrompt) === mb_strtolower($transcript)) {
            return true;
        }

        return 1 === preg_match('/^\s*\[[^\[\]]+\]\s*$/u', $cleanedUserPrompt);
    }
}
