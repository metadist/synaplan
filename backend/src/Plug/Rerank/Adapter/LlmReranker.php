<?php

declare(strict_types=1);

namespace App\Plug\Rerank\Adapter;

use App\AI\Service\AiFacade;
use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\Rerank\RerankCandidate;
use App\Plug\Rerank\RerankOptions;
use App\Plug\Rerank\RerankProviderInterface;
use App\Plug\Rerank\RerankResult;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;

/**
 * Listwise chat rerank. Expensive; only active when LLM_FALLBACK is on and
 * no dedicated rerank model is bound.
 *
 * @internal
 */
final readonly class LlmReranker implements RerankProviderInterface
{
    public const KEY = 'llm';
    public const PROMPT_TOPIC = 'tools:rerank_listwise';

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfig,
        private PromptRepository $prompts,
        private PlugConfigService $plugConfig,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            self::KEY,
            'Chat-model rerank (expensive)',
            'https://docs.synaplan.com/rag',
            ['PLUGS.RERANK.LLM_FALLBACK'],
            'depends on chat model',
        );
    }

    public function rerank(string $query, array $candidates, int $topK, RerankOptions $options): RerankResult
    {
        $started = hrtime(true);
        $ids = $this->parseIds($this->ask($query, $candidates, $options));
        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[$candidate->id] = $candidate;
        }

        $hits = [];
        $seen = [];
        foreach ($ids as $id) {
            if (isset($seen[$id]) || !isset($byId[$id])) {
                continue;
            }
            $seen[$id] = true;
            $candidate = $byId[$id];
            $hits[] = [
                'id' => $candidate->id,
                'text' => $candidate->text,
                'score' => 1.0 - (count($hits) * 0.01),
            ];
            if (count($hits) >= $topK) {
                break;
            }
        }

        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        return new RerankResult($hits, self::KEY, $ms);
    }

    public function health(): PlugHealth
    {
        if (!$this->plugConfig->isRerankLlmFallback()) {
            return PlugHealth::unavailable('Chat-model fallback is off');
        }

        $summary = $this->modelConfig->getSummaryModelConfig();
        if (null === $summary['model'] || null === $summary['provider']) {
            return PlugHealth::unavailable('No summarize model is bound');
        }

        return PlugHealth::available();
    }

    /**
     * @param list<RerankCandidate> $candidates
     */
    private function ask(string $query, array $candidates, RerankOptions $options): string
    {
        $prompt = $this->prompts->findByTopic(self::PROMPT_TOPIC, 0);
        $system = $prompt?->getPrompt() ?? 'Return a JSON array of candidate ids, best first.';
        $lines = [];
        foreach ($candidates as $candidate) {
            $text = $candidate->text;
            if ($options->maxCandidateChars > 0 && mb_strlen($text) > $options->maxCandidateChars) {
                $text = mb_substr($text, 0, $options->maxCandidateChars);
            }
            $lines[] = '- id='.$candidate->id.': '.$text;
        }

        $summary = $this->modelConfig->getSummaryModelConfig();
        $chatOptions = [];
        if (null !== $summary['model']) {
            $chatOptions['model'] = $summary['model'];
        }
        if (null !== $summary['provider']) {
            $chatOptions['provider'] = $summary['provider'];
        }

        $response = $this->aiFacade->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Query:\n".$query."\n\nCandidates:\n".implode("\n", $lines)],
        ], null, $chatOptions);

        $content = $response['content'] ?? '';

        return \is_string($content) ? $content : '';
    }

    /**
     * @return list<string>
     */
    private function parseIds(string $raw): array
    {
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $trimmed);
        }

        try {
            $decoded = json_decode($trimmed, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $item) {
            if (\is_string($item) && '' !== $item) {
                $ids[] = $item;
            } elseif (\is_int($item) || (\is_string($item) && is_numeric($item))) {
                $ids[] = (string) $item;
            } elseif (\is_array($item) && \is_string($item['id'] ?? null)) {
                $ids[] = $item['id'];
            }
        }

        return $ids;
    }
}
