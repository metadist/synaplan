<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\Context\ContextChunker;
use App\Service\Context\ContextCondenser;
use App\Service\Context\ContextFittingConfig;
use App\Service\Context\ModelContextWindow;
use App\Service\Context\TokenEstimator;
use App\Service\Message\Handler\FileAnalysisHandler;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A 250 kB spreadsheet directed at a large-window model used to be pasted
 * verbatim into the system prompt with a flat `max_tokens: 4000`. These tests
 * pin the fitted behaviour: the attachment is budgeted against the model's
 * catalog window, condensed with the user's question when it does not fit,
 * and the completion budget scales with the model.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class FileAnalysisHandlerContextFitTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private ModelRepository&MockObject $modelRepository;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);

        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('answer-model');
    }

    public function testSmallDocumentIsPassedVerbatimWithScaledOutputBudget(): void
    {
        // Answering model: 1M window, 128k output → output budget hits the 16k ceiling.
        $this->modelConfigService->method('getDefaultModel')->willReturn(340);
        $this->modelRepository->method('find')->willReturn($this->model(1050000, 128000));

        $captured = null;
        $options = null;
        $this->aiFacade->expects(self::once())->method('chat')->willReturnCallback(function (array $messages, ?int $userId, array $opts) use (&$captured, &$options): array {
            $captured = $messages;
            $options = $opts;

            return ['content' => 'ok', 'provider' => 'openai', 'model' => 'answer-model'];
        });

        $this->handler()->handle($this->message('Summarize.', 'Quarterly revenue rose by 4 %.'), [], []);

        self::assertStringContainsString('Quarterly revenue rose by 4 %.', $captured[0]['content']);
        self::assertStringNotContainsString('[Note:', $captured[0]['content']);
        self::assertSame(16000, $options['max_tokens']);
    }

    public function testOversizedDocumentIsCondensedWithTheQuestionBeforeTheAnswerCall(): void
    {
        // Answering model with a SMALL window (32k) so a 200 kB sheet cannot fit.
        $this->modelConfigService->method('getDefaultModel')->willReturnCallback(
            static fn (string $capability): int => 'ANALYZE' === $capability ? 9 : 76,
        );
        $this->modelRepository->method('find')->willReturnCallback(
            fn (int $id): Model => 9 === $id ? $this->model(32000, 4096) : $this->model(131072, 32768),
        );

        $rows = [];
        for ($i = 1; $i <= 5000; ++$i) {
            $rows[] = sprintf('| %d | Region-%d | %d.00 |', $i, $i % 5, $i * 100);
        }
        $sheet = "## Sales\n\n| ID | Region | Revenue |\n| --- | --- | --- |\n".implode("\n", $rows);

        $condenserPrompts = [];
        $answerMessages = null;
        $answerOptions = null;
        $this->aiFacade->method('chat')->willReturnCallback(function (array $messages, ?int $userId, array $opts) use (&$condenserPrompts, &$answerMessages, &$answerOptions): array {
            if (str_contains($messages[0]['content'], 'You condense one part of a large document')) {
                $condenserPrompts[] = $messages[1]['content'];

                return ['content' => 'Region-0 total 1,000,000; Region-1 total 1,100,000 (condensed part)', 'provider' => 'groq', 'model' => 'condenser'];
            }
            $answerMessages = $messages;
            $answerOptions = $opts;

            return ['content' => 'Region-1 leads.', 'provider' => 'openai', 'model' => 'answer-model'];
        });

        $result = $this->handler()->handle($this->message('Which region has the highest revenue?', $sheet, 'sales.xlsx', 'xlsx'), [], []);

        self::assertSame('Region-1 leads.', $result['content']);
        self::assertNotEmpty($condenserPrompts, 'the condenser ran before the answer call');
        foreach ($condenserPrompts as $prompt) {
            self::assertStringContainsString('Which region has the highest revenue?', $prompt, 'the user question is the condensing lens');
        }

        self::assertNotNull($answerMessages);
        $system = $answerMessages[0]['content'];
        self::assertStringContainsString('was condensed', $system, 'the model is told it sees a condensed view');
        self::assertStringContainsString('Region-1 total 1,100,000', $system);
        self::assertStringNotContainsString('| 4999 | Region-4 |', $system, 'raw bulk rows must not reach the answering model');
        self::assertLessThan(60000, strlen($system), 'system prompt stays inside the 32k-token window');
        self::assertSame(4096, $answerOptions['max_tokens'], 'output budget follows the small model (floor 4000, ceiling = catalog max_output)');
    }

    private function model(int $contextWindow, int $maxOutput): Model
    {
        $model = new Model();
        $model->setJson(['meta' => ['context_window' => $contextWindow, 'max_output' => $maxOutput]]);

        return $model;
    }

    private function handler(): FileAnalysisHandler
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn(null);
        $config = new ContextFittingConfig($configRepository);
        $estimator = new TokenEstimator();
        $window = new ModelContextWindow($this->modelRepository, $estimator, $config);

        $condenser = new ContextCondenser(
            $this->aiFacade,
            $this->modelConfigService,
            $window,
            new ContextChunker(),
            $estimator,
            $config,
            $this->createMock(RateLimitService::class),
            $this->createMock(UserRepository::class),
            new NullLogger(),
        );

        return new FileAnalysisHandler(
            $this->aiFacade,
            $this->modelConfigService,
            new NullLogger(),
            sys_get_temp_dir(),
            $condenser,
            $window,
        );
    }

    private function message(string $text, string $fileText, string $name = 'report.pdf', string $type = 'pdf'): Message&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(1);
        $file->method('getFileName')->willReturn($name);
        $file->method('getFileType')->willReturn($type);
        $file->method('getFilePath')->willReturn('13/000/'.$name);
        $file->method('getFileText')->willReturn($fileText);
        $file->method('getStatus')->willReturn('processed');

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(42);
        $message->method('getUserId')->willReturn(7);
        $message->method('getFiles')->willReturn(new ArrayCollection([$file]));
        $message->method('getText')->willReturn($text);

        return $message;
    }
}
