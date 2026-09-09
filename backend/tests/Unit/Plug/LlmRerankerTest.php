<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\AI\Service\AiFacade;
use App\Entity\Prompt;
use App\Plug\PlugConfigService;
use App\Plug\Rerank\Adapter\LlmReranker;
use App\Plug\Rerank\RerankCandidate;
use App\Plug\Rerank\RerankOptions;
use App\Repository\ConfigRepository;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use PHPUnit\Framework\TestCase;

final class LlmRerankerTest extends TestCase
{
    public function testParsesJsonArrayOfIds(): void
    {
        $reranker = $this->reranker('["b","a"]');
        $result = $reranker->rerank('q', $this->candidates(), 2, new RerankOptions());

        $this->assertSame(['b', 'a'], array_column($result->hits, 'id'));
        $this->assertSame('llm', $result->provider);
    }

    public function testParseErrorReturnsEmptyHitsForStageFallback(): void
    {
        $reranker = $this->reranker('not-json');
        $result = $reranker->rerank('q', $this->candidates(), 2, new RerankOptions());

        $this->assertSame([], $result->hits);
    }

    /**
     * @return list<RerankCandidate>
     */
    private function candidates(): array
    {
        return [
            new RerankCandidate('a', 'first', 0.5),
            new RerankCandidate('b', 'second', 0.4),
        ];
    }

    private function reranker(string $content): LlmReranker
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->method('chat')->willReturn(['content' => $content]);
        $models = $this->createMock(ModelConfigService::class);
        $models->method('getSummaryModelConfig')->willReturn([
            'model' => 'test',
            'provider' => 'test',
            'model_id' => 1,
        ]);
        $prompt = new Prompt();
        $prompt->setPrompt('return ids');
        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('findByTopic')->willReturn($prompt);
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'RERANK.LLM_FALLBACK' === $setting ? '1' : null
        );

        return new LlmReranker($ai, $models, $prompts, new PlugConfigService($repo));
    }
}
