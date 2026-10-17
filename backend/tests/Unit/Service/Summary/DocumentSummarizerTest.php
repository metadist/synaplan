<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Summary;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\Summary\DocumentSummarizer;
use App\Service\Summary\SummaryCall;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocumentSummarizerTest extends TestCase
{
    public function testTheSummaryModelAnswersAndTheUsageIsRecordedUnderTheCallersKind(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::once())->method('chat')
            ->with([['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Summarize: a long text']], 7, ['temperature' => 0.3, 'provider' => 'groq', 'model' => 'llama'])
            ->willReturn(['content' => "  A short text.\n", 'provider' => 'groq', 'model' => 'llama', 'usage' => ['total_tokens' => 9]]);
        $usage = $this->createMock(RateLimitService::class);
        $usage->expects(self::once())->method('recordUsage')->with(self::anything(), 'FILE_SUMMARY', [
            'provider' => 'groq', 'model' => 'llama', 'model_id' => 12, 'usage' => ['total_tokens' => 9],
            'response_text' => 'A short text.', 'input_text' => 'Summarize: a long text',
        ]);

        $result = $this->summarizer($ai, $usage, ['model' => 'llama', 'provider' => 'groq', 'model_id' => 12])
            ->summarize($this->user(), new SummaryCall('Be brief.', 'Summarize: a long text', 0.3, 'FILE_SUMMARY', 'Summarize: a long text'));

        self::assertSame(['summary' => 'A short text.', 'provider' => 'groq', 'model' => 'llama'], $result);
    }

    public function testWithoutASummaryModelTheProviderDefaultAnswersAndTheLatencyIsRecorded(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::once())->method('chat')->with(self::anything(), 7, ['temperature' => 0.5])->willReturn(['content' => 'Done.']);
        $usage = $this->createMock(RateLimitService::class);
        $usage->expects(self::once())->method('recordUsage')->with(self::anything(), 'DOC_SUMMARY', self::callback(
            static fn (array $metadata): bool => 'unknown' === $metadata['provider'] && null === $metadata['model_id'] && \is_int($metadata['latency']) && 'the document' === $metadata['input_text'],
        ));

        $result = $this->summarizer($ai, $usage, null)
            ->summarize($this->user(), new SummaryCall('Summarize.', 'Configuration: …', 0.5, 'DOC_SUMMARY', 'the document', microtime(true)));

        self::assertSame(['summary' => 'Done.', 'provider' => null, 'model' => null], $result);
    }

    /**
     * @param array{model: ?string, provider: ?string, model_id: ?int}|null $model null: resolving the model fails
     */
    private function summarizer(AiFacade $ai, RateLimitService $usage, ?array $model): DocumentSummarizer
    {
        $models = $this->createStub(ModelConfigService::class);
        if (null === $model) {
            $models->method('getSummaryModelConfig')->willThrowException(new \RuntimeException('database down'));
        } else {
            $models->method('getSummaryModelConfig')->willReturn($model);
        }

        return new DocumentSummarizer($ai, $models, $usage, new NullLogger());
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }
}
