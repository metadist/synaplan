<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Entity\Chat;
use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Service\Chat\Loop\ChatLoopConfig;
use App\Service\Chat\Loop\ChatLoopGate;
use App\Service\Chat\Loop\ChatRunLoopLimits;
use App\Service\Chat\Loop\ChatRunStartBudget;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatLoopGateTest extends TestCase
{
    private const OWNER = 7;

    public function testAPlainStreamedTurnInTheOwnChatRunsOnTheLoop(): void
    {
        self::assertTrue($this->gate()->forTurn($this->message(), [ChatLoopGate::OPTION => true], []));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function excludingOptions(): iterable
    {
        yield 'not the web chat stream' => [[]];
        yield 'incognito' => [[ChatLoopGate::OPTION => true, 'incognito' => true]];
        yield 'guest' => [[ChatLoopGate::OPTION => true, 'is_guest_mode' => true]];
        yield 'widget' => [[ChatLoopGate::OPTION => true, 'is_widget_mode' => true]];
        yield 'again' => [[ChatLoopGate::OPTION => true, 'is_again' => true]];
        yield 'continue' => [[ChatLoopGate::OPTION => true, 'is_continuation' => true]];
        yield 'agent' => [[ChatLoopGate::OPTION => true, 'agentId' => 3]];
        yield 'saved task' => [[ChatLoopGate::OPTION => true, 'saved_task_id' => 4]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('excludingOptions')]
    public function testTurnsThatKeepTodaysPath(array $options): void
    {
        self::assertFalse($this->gate()->forTurn($this->message(), $options, []));
    }

    public function testATurnWithAFileKeepsTodaysPath(): void
    {
        self::assertFalse($this->gate()->forTurn($this->message()->setFile(1), [ChatLoopGate::OPTION => true], []));
    }

    public function testATurnInSomeoneElsesChatKeepsTodaysPath(): void
    {
        $message = $this->message();
        $message->getChat()?->setUserId(self::OWNER + 1);

        self::assertFalse($this->gate()->forTurn($message, [ChatLoopGate::OPTION => true], []));
    }

    public function testADocumentReplyOrADeferredRoutingKeepsTodaysPath(): void
    {
        self::assertFalse($this->gate()->forTurn($this->message(), [ChatLoopGate::OPTION => true], ['topic' => 'officemaker']));
        self::assertFalse($this->gate()->forTurn($this->message(), [ChatLoopGate::OPTION => true], ['defer_routing_to_chat' => true]));
    }

    public function testNothingRunsOnTheLoopWhileItIsOff(): void
    {
        self::assertFalse($this->gate('0')->forTurn($this->message(), [ChatLoopGate::OPTION => true], []));
    }

    public function testAModelThatCannotCallToolsWhileStreamingKeepsTodaysPath(): void
    {
        self::assertFalse($this->gate('1', 'ollama')->forTurn($this->message(), [ChatLoopGate::OPTION => true], []));
    }

    public function testTheChosenModelDecidesBeforeTheDefault(): void
    {
        $models = $this->createMock(ModelConfigService::class);
        $models->expects(self::never())->method('getDefaultModel');
        $models->expects(self::once())->method('getProviderForModel')->with(42)->willReturn('anthropic');

        self::assertTrue($this->gate('1', null, $models)->forTurn($this->message(), [ChatLoopGate::OPTION => true, 'model_id' => 42], []));
    }

    public function testTheAnswerDoesNotChangeWithinATurn(): void
    {
        $gate = $this->gate();
        $message = $this->message();

        self::assertTrue($gate->forTurn($message, [ChatLoopGate::OPTION => true], []));
        self::assertTrue($gate->forTurn($message, ['incognito' => true], []));
        self::assertFalse($gate->forTurn($this->message(), ['incognito' => true], []));
    }

    private function gate(string $env = '1', ?string $provider = 'anthropic', ?ModelConfigService $models = null): ChatLoopGate
    {
        if (null === $models) {
            $models = $this->createStub(ModelConfigService::class);
            $models->method('getDefaultModel')->willReturn(5);
            $models->method('getProviderForModel')->willReturn($provider);
        }

        $start = new ChatRunStartBudget(new SwitchedChatRunBudget(), $this->createStub(RateLimitService::class), new ChatRunLoopLimits());

        return new ChatLoopGate(new ChatLoopConfig($this->createStub(ConfigRepository::class), $env), $models, new ToolCallingCapability(), $start);
    }

    private function message(): Message
    {
        return (new Message())
            ->setUserId(self::OWNER)
            ->setChat((new Chat())->setUserId(self::OWNER))
            ->setDirection('IN')
            ->setText('Hello');
    }
}
