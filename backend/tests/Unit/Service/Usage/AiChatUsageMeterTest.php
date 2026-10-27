<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Usage;

use App\Entity\User;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\RateLimitService;
use App\Service\Usage\AiChatUsageMeter;
use App\Service\Usage\RecordedUsage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AiChatUsageMeterTest extends TestCase
{
    public function testACallerThatMetersIsLeftAlone(): void
    {
        $limits = $this->createMock(RateLimitService::class);
        $limits->expects($this->never())->method('recordUsage');

        $meter = $this->meter($limits, $this->createStub(UserRepository::class), $this->createStub(ModelRepository::class));

        $this->assertNull($meter->record(4, ['provider' => 'anthropic', 'model' => 'claude-fable-5-1'], [], [
            AiChatUsageMeter::CALLER_METERS => true,
        ]));
    }

    public function testAChatWithNoUserIsNotARow(): void
    {
        $limits = $this->createMock(RateLimitService::class);
        $limits->expects($this->never())->method('recordUsage');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $meter = new AiChatUsageMeter(
            $limits,
            $this->createStub(UserRepository::class),
            $this->createStub(ModelRepository::class),
            $logger,
        );

        $this->assertNull($meter->record(null, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'], [], []));
    }

    public function testProviderTokensAreWrittenAgainstTheCatalogRow(): void
    {
        $user = $this->createStub(User::class);
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with(7)->willReturn($user);

        $models = $this->createMock(ModelRepository::class);
        $models->expects($this->once())->method('findChatIdByProvider')->with('anthropic', 'claude-fable-5-1')->willReturn(338);

        $limits = $this->createMock(RateLimitService::class);
        $limits->expects($this->once())->method('recordUsage')->with(
            $user,
            AiChatUsageMeter::ACTION,
            $this->callback(static function (array $metadata): bool {
                return 338 === $metadata['model_id']
                    && 'claude-fable-5-1' === $metadata['model']
                    && 120 === ($metadata['usage']['prompt_tokens'] ?? null)
                    && 40 === ($metadata['usage']['completion_tokens'] ?? null);
            }),
        )->willReturn(new RecordedUsage('0.010000', '0.009000', 120, 40, 160));

        $meter = $this->meter($limits, $users, $models);
        $recorded = $meter->record(7, [
            'provider' => 'anthropic',
            'model' => 'claude-fable-5-1',
            'content' => 'done',
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40, 'total_tokens' => 160],
        ], [['role' => 'user', 'content' => 'hello']], []);

        $this->assertNotNull($recorded);
        $this->assertSame('0.010000', $recorded->chargedCost);
    }

    public function testAttributionIsOnTheRowAndOffTheProviderOptions(): void
    {
        $options = [
            'model' => 'claude-fable-5-1',
            AiChatUsageMeter::USAGE_SOURCE => 'whatsapp',
            AiChatUsageMeter::USAGE_CHAT_ID => '15',
            AiChatUsageMeter::USAGE_AGENT_ID => 4,
            AiChatUsageMeter::USAGE_AGENT_VERSION_ID => 8,
            AiChatUsageMeter::CALLER_METERS => true,
        ];
        $context = AiChatUsageMeter::claim($options);
        $this->assertSame(['model' => 'claude-fable-5-1'], $options);
        $this->assertTrue($context[AiChatUsageMeter::CALLER_METERS]);

        $limits = $this->createMock(RateLimitService::class);
        $limits->expects($this->never())->method('recordUsage');
        $meter = $this->meter($limits, $this->createStub(UserRepository::class), $this->createStub(ModelRepository::class));
        $this->assertNull($meter->record(7, ['provider' => 'anthropic', 'model' => 'claude-fable-5-1'], [], $options, $context));

        unset($context[AiChatUsageMeter::CALLER_METERS]);
        $user = $this->createStub(User::class);
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with(7)->willReturn($user);
        $models = $this->createMock(ModelRepository::class);
        $models->expects($this->never())->method('findChatIdByProvider');
        $limits = $this->createMock(RateLimitService::class);
        $limits->expects($this->once())->method('recordUsage')->with(
            $user,
            AiChatUsageMeter::ACTION,
            $this->callback(static function (array $metadata): bool {
                return 'whatsapp' === $metadata['source']
                    && 15 === $metadata['chat_id']
                    && 4 === $metadata['agentId']
                    && 8 === $metadata['agentVersionId']
                    && 338 === $metadata['model_id'];
            }),
        )->willReturn(new RecordedUsage('0.010000', '0.009000', 1, 1, 2, false));

        $recorded = $this->meter($limits, $users, $models)->record(7, [
            'provider' => 'anthropic',
            'model' => 'claude-fable-5-1',
            'content' => 'done',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], [], ['model_id' => 338], $context);

        $this->assertNotNull($recorded);
        $this->assertFalse($recorded->priceKnown);
    }

    public function testMissingProviderTokensFallThroughToTheText(): void
    {
        $user = $this->createStub(User::class);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $models = $this->createStub(ModelRepository::class);
        $models->method('findChatIdByProvider')->willReturn(249);

        $limits = $this->createMock(RateLimitService::class);
        $limits->expects($this->once())->method('recordUsage')->with(
            $user,
            AiChatUsageMeter::ACTION,
            $this->callback(static function (array $metadata): bool {
                return [] === $metadata['usage']
                    && 'plan the week' === $metadata['input_text']
                    && 'here is the plan' === $metadata['response_text'];
            }),
        )->willReturn(new RecordedUsage('0.000100', '0.000090', 4, 4, 8));

        $meter = $this->meter($limits, $users, $models);
        $meter->record(3, [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'content' => 'here is the plan',
            'usage' => [],
        ], [['role' => 'user', 'content' => 'plan the week']], []);
    }

    private function meter(RateLimitService $limits, UserRepository $users, ModelRepository $models): AiChatUsageMeter
    {
        return new AiChatUsageMeter($limits, $users, $models, $this->createStub(LoggerInterface::class));
    }
}
