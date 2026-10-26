<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use App\AI\Service\AiFacade;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks a judge model whether a claim is in a report, or whether a passage
 * supports a statement. The prompt names no arm.
 */
final readonly class JudgeClient
{
    public function __construct(
        private AiFacade $ai,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function factPrompt(string $assertion, string $report): string
    {
        return $this->fill('fact.md', ['{assertion}' => $assertion, '{report}' => $report]);
    }

    public function citationPrompt(string $statement, string $quote): string
    {
        return $this->fill('citation.md', ['{statement}' => $statement, '{quote}' => $quote]);
    }

    /**
     * @param list<string> $judgeKeys provider:model or provider:model:tag
     *
     * @return list<array{judge: string, verdict: string}>
     */
    public function judgeFact(string $assertion, string $report, array $judgeKeys, int $userId): array
    {
        $out = [];
        foreach ($judgeKeys as $key) {
            $out[] = $this->ask($key, $this->factPrompt($assertion, $report), ['present', 'absent', 'contradicted'], $userId);
        }

        return array_map(static fn (array $row): array => ['judge' => $row['judge'], 'verdict' => $row['verdict']], $out);
    }

    /**
     * @return array{judge: string, verdict: string, tokens: int}
     */
    public function judgeCitation(string $statement, string $quote, string $judgeKey, int $userId): array
    {
        return $this->ask($judgeKey, $this->citationPrompt($statement, $quote), ['supports', 'does_not_support'], $userId);
    }

    /**
     * @param list<string> $words
     *
     * @return array{judge: string, verdict: string, tokens: int}
     */
    private function ask(string $key, string $prompt, array $words, int $userId): array
    {
        [$provider, $model] = $this->parse($key);
        $response = $this->ai->chat($prompt, $userId, [
            'provider' => $provider,
            'model' => $model,
        ]);
        $usage = \is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return [
            'judge' => $key,
            'verdict' => $this->verdict((string) ($response['content'] ?? ''), $words),
            'tokens' => (int) ($usage['total_tokens'] ?? 0),
        ];
    }

    /**
     * @param array<string, string> $values
     */
    private function fill(string $file, array $values): string
    {
        $path = $this->projectDir.'/tests/Eval/research/judges/'.$file;
        if (!is_file($path)) {
            throw new \RuntimeException('The judge prompt '.$file.' is missing.');
        }

        return strtr((string) file_get_contents($path), $values);
    }

    /**
     * The model keeps everything after the provider, so an Ollama tag such
     * as "llama3:8b" reaches the provider intact.
     *
     * @return array{0: string, 1: string}
     */
    private function parse(string $key): array
    {
        $parts = explode(':', $key, 2);
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new \RuntimeException('A judge key looks like provider:model. Got "'.$key.'".');
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @param list<string> $words
     */
    private function verdict(string $content, array $words): string
    {
        $word = strtolower(trim((string) strtok(trim($content), " \n\t"), " .,:;*`'\""));

        return \in_array($word, $words, true) ? $word : 'uncertain';
    }
}
