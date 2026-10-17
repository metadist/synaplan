<?php

namespace App\Service\Message\Handler;

use App\AI\Stream\StreamChunk;
use App\Entity\Message;
use App\Service\Message\Handler\Media\MediaChatHandOff;
use App\Service\Message\Handler\Media\MediaGenerationRun;
use App\Service\Message\Handler\Media\MediaModelResolver;
use App\Service\Message\Handler\Media\MediaPromptResolver;
use App\Service\Message\Handler\Media\MediaReferenceResolver;
use App\Service\Message\Handler\Media\MediaRequestGuard;
use App\Service\Message\Handler\Media\MediaTurnBudget;
use App\Service\Message\Handler\Media\MediaTurnMemory;
use App\Service\Message\Handler\Media\MediaTurnRequest;
use App\Service\Message\ProgressCallback;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Media turns (images, videos, speech) as named stages: the pictures a turn
 * starts from, its prompt and model, the guards, the limits, then the render
 * with its storage and billing. A misclassified audio turn is answered as
 * chat. The user's prompt is used as written; the frontend offers "Enhance
 * prompt".
 */
#[AutoconfigureTag('app.message.handler')]
final readonly class MediaGenerationHandler implements MessageHandlerInterface
{
    public function __construct(
        private MediaReferenceResolver $referenceResolver,
        private MediaPromptResolver $promptResolver,
        private MediaModelResolver $modelResolver,
        private MediaRequestGuard $guard,
        private MediaChatHandOff $chatHandOff,
        private MediaTurnMemory $memory,
        private MediaTurnBudget $budget,
        private MediaGenerationRun $run,
    ) {
    }

    public function getName(): string
    {
        return 'image_generation'; // Keep legacy name for backward compatibility
    }

    /**
     * Non-streaming handle method (required by interface).
     * Delegates to handleStream with a blocking accumulator.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options        forwarded to {@see handleStream()} so callers (email
     *                                             webhook, generic API) can disable memories or set the
     *                                             channel exactly like the SSE path
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $content = '';
        $metadata = [];

        // Simple accumulator callback. visibleText() keeps only answer text —
        // structured reasoning chunks must never end up in the message (#1067).
        $streamCallback = function ($chunk) use (&$content, &$metadata) {
            $content .= StreamChunk::visibleText($chunk);
            // Merge metadata if present in chunk
            if (is_array($chunk) && isset($chunk['metadata']) && is_array($chunk['metadata'])) {
                $metadata = array_merge($metadata, $chunk['metadata']);
            }
        };

        // Call streaming handler — forward options so non-streaming callers
        // (email/webhook) preserve the same channel/disable flags as SSE.
        $result = $this->handleStream(
            $message,
            $thread,
            $classification,
            $streamCallback,
            $progressCallback,
            $options
        );

        // Return accumulated result
        return [
            'content' => $content,
            'metadata' => $result['metadata'] ?? [],
        ];
    }

    /**
     * Handle media generation with streaming.
     *
     * Memory extraction is queued once the turn stays on the media path, after
     * the chat hand-off decision but before the slow provider call, like
     * ChatHandler: a failed picture still keeps "ich liebe Hunde" (#880). A
     * turn handed to ChatHandler is extracted there.
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        ProgressCallback::notify($progressCallback, 'analyzing', 'Understanding your request...');

        $references = $this->referenceResolver->resolve($message, $thread, $classification, $options);
        $prompt = $this->promptResolver->resolve($message, $thread, $classification, $references, $progressCallback);
        $choice = $this->modelResolver->choose($message, $classification, $prompt);
        if (null === $choice) {
            $answer = $this->guard->undeterminedMediaType($classification)->answer($streamCallback);
            $this->memory->dispatch($message, $thread, $classification, $options);

            return $answer;
        }

        if ($this->guard->answersAsChat($choice, $prompt, $message)) {
            return $this->chatHandOff->answerAsChatInstead($message, $thread, $classification, $streamCallback, $progressCallback, $options, $prompt->text);
        }

        $this->memory->dispatch($message, $thread, $classification, $options);
        $request = new MediaTurnRequest(
            $message,
            $thread,
            $classification,
            $options,
            $prompt->text,
            $choice->mediaType,
            $choice->references,
            $this->modelResolver->resolve($message, $choice),
            $this->modelResolver->effectiveUserId($message),
        );
        $refusal = $this->guard->refusal($request);
        if (null !== $refusal) {
            return $refusal->answer($streamCallback);
        }

        $this->announce($request, $progressCallback);
        $user = $this->budget->user($message);
        $refusal = $this->budget->refusal($request, $user);
        if (null !== $refusal) {
            return $refusal->answer($streamCallback);
        }

        return $this->run->execute($request, $user, $streamCallback, $progressCallback);
    }

    /**
     * The status the client narrates in the user's language ("Generating your
     * image with …"), sent before the limits are checked.
     */
    private function announce(MediaTurnRequest $request, ?callable $progressCallback): void
    {
        $provider = (string) $request->model->provider;
        $providerName = ucfirst($provider);
        $modelName = $request->model->name;
        $mediaTypeLabel = $request->mediaTypeLabel();
        ProgressCallback::notify($progressCallback, 'generating', "AI is crafting your $mediaTypeLabel with $providerName $modelName", [
            'provider' => $provider,
            'model_name' => $modelName,
            'media_type' => $mediaTypeLabel,
            'stage' => 'request_sent',
        ]);
    }
}
