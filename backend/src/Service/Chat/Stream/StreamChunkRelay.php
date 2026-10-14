<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Service\Exception\StreamCancelledException;
use App\Service\Media\MediaCancellationStore;
use App\Service\Realtime\SseWriter;

/**
 * Relays a model's streamed answer to the client while collecting it.
 *
 * Reasoning streams live and is also buffered into one `<think>` block that
 * goes out with the first answer token: that block is what gets stored, and
 * the client reconciles the live part against it. An answer whose first
 * chunk starts with `{` is buffered as JSON — a legacy {"BTEXT": …} envelope
 * is unwrapped, and a document envelope announces "writing" (with its file
 * name) instead of leaking the raw payload.
 *
 * A disconnect does not end the turn: it keeps generating so the finished
 * answer is stored for the person's return (#1142, #1223, #1225). Only an
 * explicit Stop, flagged through /stop-stream, aborts the stream.
 */
final class StreamChunkRelay
{
    /** Everything the answer said, including a buffered JSON envelope. */
    public string $responseText = '';

    public int $chunkCount = 0;

    /** The provider's finish signal ("length" = the token limit was hit). */
    public mixed $finishReason = null;

    /** What the client actually received as `data` before the stream ended. */
    public string $streamedVisibleText = '';

    private string $reasoningBuffer = '';
    private bool $hasReasoningStarted = false;
    private string $jsonBuffer = '';
    private bool $isBufferingJson = false;
    private bool $fileGenAnnounced = false;
    private ?string $fileGenFilename = null;

    public function __construct(
        private readonly SseWriter $sse,
        private readonly MediaCancellationStore $cancellationStore,
        private readonly string $trackId,
    ) {
    }

    /**
     * @param string|array<string, mixed> $chunk plain text from older providers, typed chunks from newer ones
     */
    public function onChunk(mixed $chunk): void
    {
        if ('' !== $this->trackId && $this->cancellationStore->isCancelled($this->trackId)) {
            throw new StreamCancelledException('Stream cancelled by user');
        }

        if (is_array($chunk)) {
            if (!$this->relayTypedChunk($chunk)) {
                return;
            }
        } elseif (!$this->relayText($chunk)) {
            return;
        }

        if (0 === $this->chunkCount) {
            error_log('🔵 StreamController: Started streaming');
        }
        ++$this->chunkCount;
    }

    /**
     * Closes an open reasoning block and keeps a JSON envelope the stream
     * never completed. Gemini follows the officemaker "respond with ONLY
     * JSON" contract literally, so a whole document can sit in the buffer;
     * the file-generation parse after the stream needs it.
     */
    public function finish(): void
    {
        if ($this->hasReasoningStarted) {
            $this->reasoningBuffer .= '</think>';
            $this->sse->send('data', ['chunk' => $this->reasoningBuffer]);
            $this->responseText .= $this->reasoningBuffer;
        }

        $this->streamedVisibleText = $this->responseText;
        if ($this->isBufferingJson && '' !== trim($this->jsonBuffer)) {
            $this->responseText .= $this->jsonBuffer;
            $this->jsonBuffer = '';
            $this->isBufferingJson = false;
        }
    }

    /**
     * @param array<string, mixed> $chunk
     *
     * @return bool whether the chunk counts as a streamed chunk
     */
    private function relayTypedChunk(array $chunk): bool
    {
        $type = $chunk['type'] ?? 'content';
        $content = $chunk['content'] ?? '';

        if ('finish' === $type) {
            $this->finishReason = $chunk['finish_reason'] ?? null;

            return false;
        }

        if ('reasoning' !== $type) {
            $this->closeReasoning();
            $this->responseText .= $content;
            if (!empty($content)) {
                $this->sse->send('data', ['chunk' => $content]);
            }

            return true;
        }

        if (!$this->hasReasoningStarted) {
            $this->reasoningBuffer = '<think>';
            $this->hasReasoningStarted = true;
            // A status the moment the model starts reasoning, so the bubble
            // does not sit empty for the whole reasoning window. No message:
            // the client localizes the label itself.
            $this->sse->send('thinking', [
                'metadata' => [],
                'timestamp' => microtime(true),
            ]);
        }
        $this->reasoningBuffer .= $content;
        if ('' !== $content) {
            $this->sse->send('reasoning', ['chunk' => $content]);
        }

        return true;
    }

    /**
     * @return bool whether the chunk counts as a streamed chunk
     */
    private function relayText(mixed $chunk): bool
    {
        $this->closeReasoning();

        // `'' !== trim()`, not empty(): PHP calls "0" empty.
        if (is_string($chunk) && '' !== trim($chunk)
            && !$this->isBufferingJson && 0 === $this->chunkCount && str_starts_with(trim($chunk), '{')) {
            $this->isBufferingJson = true;
            $this->jsonBuffer = $chunk;
            ++$this->chunkCount;

            return false;
        }

        if ($this->isBufferingJson) {
            $this->bufferJson((string) $chunk);

            return false;
        }

        $this->responseText .= $chunk;

        if (false !== strpos((string) $chunk, '<think>') || false !== strpos((string) $chunk, '</think>')) {
            error_log('🧠 StreamController: <think> tag detected in chunk: '.substr((string) $chunk, 0, 100));
        }

        // A standalone "0" is a real character, not an empty chunk.
        if ('' !== $chunk) {
            $this->sse->send('data', ['chunk' => $chunk]);
        }

        return true;
    }

    private function bufferJson(string $chunk): void
    {
        $this->jsonBuffer .= $chunk;
        $this->announceDocument();

        if (!str_contains($this->jsonBuffer, '}')) {
            return;
        }

        $trimmed = trim($this->jsonBuffer);
        $lastBrace = strrpos($trimmed, '}');
        if (false === $lastBrace) {
            return;
        }

        // Models sometimes write `"BFILE": \n}` instead of `"BFILE": 0`.
        $potentialJson = substr($trimmed, 0, $lastBrace + 1);
        $potentialJson = preg_replace('/"BFILE":\s*\n/', '"BFILE": 0'."\n", $potentialJson);
        $potentialJson = preg_replace('/"BFILE":\s*\r\n/', '"BFILE": 0'."\r\n", (string) $potentialJson);
        $potentialJson = preg_replace('/"BFILE":\s*}/', '"BFILE": 0}', (string) $potentialJson);

        try {
            $jsonData = json_decode((string) $potentialJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (is_array($jsonData) && isset($jsonData['BTEXT'])) {
            $extractedText = $jsonData['BTEXT'];
            $this->responseText .= $extractedText;
            $this->sse->send('data', ['chunk' => $extractedText]);

            $this->isBufferingJson = false;
            $this->jsonBuffer = '';
        }
    }

    /**
     * While a document envelope is written, no data chunk reaches the client:
     * announce the phase, and the file name as soon as it is readable, so the
     * bubble shows "writing document" instead of staying empty.
     */
    private function announceDocument(): void
    {
        if (!$this->fileGenAnnounced && str_contains($this->jsonBuffer, 'BFILEPATH')) {
            $this->fileGenAnnounced = true;
            $this->sse->send('generating_file', [
                'metadata' => ['stage' => 'writing'],
            ]);
        }

        if ($this->fileGenAnnounced && null === $this->fileGenFilename
            && preg_match('/"BFILEPATH"\s*:\s*"([^"]+)"/', $this->jsonBuffer, $fileGenMatch)) {
            $this->fileGenFilename = $fileGenMatch[1];
            $this->sse->send('generating_file', [
                'metadata' => [
                    'stage' => 'writing',
                    'filename' => $this->fileGenFilename,
                ],
            ]);
        }
    }

    private function closeReasoning(): void
    {
        if (!$this->hasReasoningStarted) {
            return;
        }

        $this->reasoningBuffer .= '</think>';
        $this->sse->send('data', ['chunk' => $this->reasoningBuffer]);
        $this->responseText .= $this->reasoningBuffer;
        $this->reasoningBuffer = '';
        $this->hasReasoningStarted = false;
    }
}
