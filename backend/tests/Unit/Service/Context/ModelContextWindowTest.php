<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Context;

use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Context\ContextFittingConfig;
use App\Service\Context\ModelContextWindow;
use App\Service\Context\TokenEstimator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ModelContextWindowTest extends TestCase
{
    private ModelRepository&MockObject $modelRepository;
    private ModelContextWindow $window;

    protected function setUp(): void
    {
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn(null);

        $this->window = new ModelContextWindow(
            $this->modelRepository,
            new TokenEstimator(),
            new ContextFittingConfig($configRepository),
        );
    }

    public function testReadsCatalogMetadata(): void
    {
        $astra = new Model();
        $astra->setJson(['meta' => ['context_window' => 1050000, 'max_output' => 128000]]);
        $this->modelRepository->method('find')->willReturn($astra);

        $spec = $this->window->forModel(340);

        self::assertSame('catalog', $spec->source);
        self::assertSame(1050000, $spec->contextTokens);
        self::assertSame(128000, $spec->maxOutputTokens);
        self::assertSame(1050000 - 16000, $spec->inputTokens(16000));
    }

    public function testFallsBackConservativelyWithoutMetadata(): void
    {
        $legacy = new Model();
        $legacy->setJson(['features' => []]);
        $this->modelRepository->method('find')->willReturn($legacy);

        $spec = $this->window->forModel(5);

        self::assertSame('fallback', $spec->source);
        self::assertSame(ContextFittingConfig::FALLBACK_CONTEXT_TOKENS, $spec->contextTokens);
        self::assertSame(ContextFittingConfig::FALLBACK_MAX_OUTPUT_TOKENS, $spec->maxOutputTokens);

        self::assertSame('fallback', $this->window->forModel(null)->source);
    }

    public function testAttachmentBudgetUsesInputShareAndTextDensity(): void
    {
        $small = new Model();
        $small->setJson(['meta' => ['context_window' => 131072, 'max_output' => 32768]]);
        $this->modelRepository->method('find')->willReturn($small);

        $table = str_repeat("| 1 | 2 | 3 |\n", 100);
        $prose = str_repeat('words and more words ', 100);

        // (131072 − 16000) × 0.55 tokens, converted with the table vs prose ratio.
        $expectedTokens = (int) floor((131072 - 16000) * 0.55);
        self::assertSame((int) floor($expectedTokens * TokenEstimator::CHARS_PER_TOKEN_TABLE), $this->window->attachmentCharBudget(76, $table));
        self::assertSame((int) floor($expectedTokens * TokenEstimator::CHARS_PER_TOKEN_PROSE), $this->window->attachmentCharBudget(76, $prose));

        // Explicit output reservation is honoured.
        $expectedTokens = (int) floor((131072 - 4000) * 0.55);
        self::assertSame((int) floor($expectedTokens * TokenEstimator::CHARS_PER_TOKEN_PROSE), $this->window->attachmentCharBudget(76, $prose, null, 4000));
    }

    public function testAnswerOutputTokensScalesWithCatalogCeiling(): void
    {
        $big = new Model();
        $big->setJson(['meta' => ['context_window' => 1050000, 'max_output' => 128000]]);
        $tiny = new Model();
        $tiny->setJson(['meta' => ['context_window' => 8192, 'max_output' => 2048]]);
        $this->modelRepository->method('find')->willReturnCallback(static fn (int $id): Model => 340 === $id ? $big : $tiny);

        self::assertSame(16000, $this->window->answerOutputTokens(340), 'capped at the ceiling');
        self::assertSame(4000, $this->window->answerOutputTokens(9), 'never below the floor');
        self::assertSame(8192, $this->window->answerOutputTokens(340, 4000, 8192));
    }
}
