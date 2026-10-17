<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Memory;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\User;
use App\Service\Memory\MemoryInputParser;
use App\Service\Memory\MemoryParseResult;
use App\Service\Memory\UserMemoryService;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;

final class MemoryInputParserTest extends TestCase
{
    private const KNOWN_MEMORY = 5;

    public function testTheActionsOfAnAnswerAreProposedAndOnlyKnownMemoriesAreNamed(): void
    {
        $answer = json_encode(['actions' => [
            ['action' => 'create', 'memory' => ['category' => 'preferences', 'key' => 'ui_theme', 'value' => 'Prefers dark mode']],
            ['action' => 'update', 'existingId' => self::KNOWN_MEMORY, 'memory' => ['key' => 'editor']],
            ['action' => 'update', 'existingId' => 999, 'memory' => ['value' => 'Uses Vim'], 'reason' => 'not known'],
            ['action' => 'delete', 'existingId' => 999],
            ['action' => 'rename'],
        ]], \JSON_THROW_ON_ERROR);

        $result = $this->parser($answer)->parse($this->user(), 'I prefer dark mode', null);

        self::assertSame(MemoryParseResult::PARSED, $result->outcome);
        self::assertSame([
            ['action' => 'create', 'memory' => ['category' => 'preferences', 'key' => 'ui_theme', 'value' => 'Prefers dark mode']],
            ['action' => 'update', 'memory' => ['category' => 'preferences', 'key' => 'editor', 'value' => 'I prefer dark mode'], 'existingId' => self::KNOWN_MEMORY],
            ['action' => 'create', 'memory' => ['category' => 'preferences', 'key' => 'note', 'value' => 'Uses Vim'], 'reason' => 'not known'],
        ], $result->actions);
    }

    public function testASingleActionAndOneActionPerLineAreReadToo(): void
    {
        $single = $this->parser('{"action": "delete", "existingId": 5}')->parse($this->user(), 'Forget my editor', null);
        $lines = $this->parser("```\n{\"action\": \"create\", \"memory\": {\"key\": \"a\"}}\nnot json\n{\"action\": \"create\", \"memory\": {\"key\": \"b\"}}\n```")->parse($this->user(), 'Two things', null);

        self::assertSame([['action' => 'delete', 'existingId' => self::KNOWN_MEMORY]], $single->actions);
        self::assertSame(['a', 'b'], array_map(static fn (array $action): string => $action['memory']['key'], $lines->actions));
    }

    public function testWhyThereIsNoAction(): void
    {
        self::assertSame(MemoryParseResult::NOT_UNDERSTOOD, $this->parser('I cannot help with that.')->parse($this->user(), 'Hello', null)->outcome);
        self::assertSame('I cannot help with that.', $this->parser('I cannot help with that.')->parse($this->user(), 'Hello', null)->answer);
        self::assertSame(MemoryParseResult::NO_MODEL, $this->parser('{}', model: false)->parse($this->user(), 'Hello', null)->outcome);
        self::assertSame(['provider_unavailable', 'model not loaded'], (static fn (MemoryParseResult $r): array => [$r->outcome, $r->providerError])($this->parser(new ProviderException('model not loaded', 'ollama'))->parse($this->user(), 'Hello', null)));
    }

    private function parser(string|ProviderException $answer, bool $model = true): MemoryInputParser
    {
        $memories = $this->createStub(UserMemoryService::class);
        $memories->method('searchMemories')->willReturn([]);
        $memories->method('getUserMemories')->willReturn([(object) ['id' => self::KNOWN_MEMORY]]);
        $ai = $this->createStub(AiFacade::class);
        if ($answer instanceof ProviderException) {
            $ai->method('chat')->willThrowException($answer);
        } else {
            $ai->method('chat')->willReturn(['content' => $answer]);
        }
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getMemoryModelConfig')->willReturn($model ? ['model' => 'mem', 'provider' => 'groq', 'model_id' => 3] : ['model' => null, 'provider' => null, 'model_id' => null]);
        $prompts = $this->createStub(PromptService::class);
        $prompts->method('getPromptWithMetadata')->willReturn(['prompt' => null]);
        $structuredOutput = $this->createStub(StructuredOutputConfig::class);
        $structuredOutput->method('isEnabled')->willReturn(false);

        return new MemoryInputParser($memories, $ai, $prompts, $models, $this->createStub(RateLimitService::class), $structuredOutput);
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }
}
