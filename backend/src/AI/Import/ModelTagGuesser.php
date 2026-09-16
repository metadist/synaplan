<?php

declare(strict_types=1);

namespace App\AI\Import;

/**
 * Guesses a model's capability tag(s) from its id/name alone.
 *
 * Name patterns only — no network call. The guess is a starting point the
 * admin edits in the import preview before applying, so a wrong guess is a
 * one-click fix, never a silent misconfiguration. The opt-in
 * {@see CapabilityProbe} can refine chat/embeddings afterwards.
 *
 * Order matters: a reranker id often also contains an embedding keyword
 * ("bge-reranker"), so rerank is matched before vectorize.
 */
final class ModelTagGuesser
{
    /**
     * Ordered pattern list; the first match wins. Each maps to the BMODELS.BTAG
     * value(s) a matching model should carry.
     *
     * @var list<array{pattern: non-empty-string, tags: list<string>}>
     */
    private const RULES = [
        ['pattern' => '/rerank/i', 'tags' => ['rerank']],
        ['pattern' => '/embed|bge|e5-|minilm|nomic|arctic-embed|gte-|mxbai/i', 'tags' => ['vectorize']],
        ['pattern' => '/whisper|parakeet/i', 'tags' => ['sound2text']],
        ['pattern' => '/tts|speech|kokoro|orpheus/i', 'tags' => ['text2sound']],
        ['pattern' => '/flux|stable-diffusion|sdxl|dall-e|dalle|gpt-image/i', 'tags' => ['text2pic']],
        ['pattern' => '/vision|-vl\b|llava|pixtral|gemma-3|qwen.*vl/i', 'tags' => ['chat', 'pic2text']],
    ];

    /**
     * @return list<string> one or more BMODELS.BTAG values, defaulting to chat
     */
    public function guess(string $id): array
    {
        $id = trim($id);
        if ('' === $id) {
            return ['chat'];
        }

        foreach (self::RULES as $rule) {
            if (1 === preg_match($rule['pattern'], $id)) {
                return $rule['tags'];
            }
        }

        return ['chat'];
    }
}
