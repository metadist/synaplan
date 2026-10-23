<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use App\AI\Service\AiFacade;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks a judge model whether a claim is in a report. The prompt names no arm.
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
        $path = $this->projectDir.'/tests/Eval/research/judges/fact.md';
        if (!is_file($path)) {
            throw new \RuntimeException('The fact judge prompt is missing.');
        }
        $template = (string) file_get_contents($path);

        return strtr($template, ['{assertion}' => $assertion, '{report}' => $report]);
    }

    /**
     * @param list<string> $judgeKeys provider:model or provider:model:tag
     *
     * @return list<array{judge: string, verdict: string}>
     */
    public function judgeFact(string $assertion, string $report, array $judgeKeys, int $userId): array
    {
        $prompt = $this->factPrompt($assertion, $report);
        $out = [];
        foreach ($judgeKeys as $key) {
            [$provider, $model] = $this->parse($key);
            $response = $this->ai->chat($prompt, $userId, [
                'provider' => $provider,
                'model' => $model,
            ]);
            $out[] = [
                'judge' => $key,
                'verdict' => $this->verdict((string) ($response['content'] ?? '')),
            ];
        }

        return $out;
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

    private function verdict(string $content): string
    {
        $word = strtolower((string) strtok(trim($content), " \n\t"));

        return \in_array($word, ['present', 'absent', 'contradicted'], true) ? $word : 'uncertain';
    }
}
