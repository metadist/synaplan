<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\Prompt;
use App\Entity\User;
use App\Service\Exception\EnhancePromptMissingException;
use App\Service\Message\InputEnhancer;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InputEnhancerTest extends TestCase
{
    public function testTheUsersChatModelRewritesTheTextUnderTheEnhancePrompt(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::once())->method('chat')
            ->with([['role' => 'system', 'content' => 'Rewrite the text.'], ['role' => 'user', 'content' => 'mail boss holiday']], 7, ['provider' => 'groq', 'model' => 'llama', 'temperature' => 0.7])
            ->willReturn(['content' => "  Write an email to my manager asking for holiday next week.\n"]);

        $enhanced = $this->enhancer($ai)->enhance($this->user(), 'mail boss holiday');

        self::assertSame('Write an email to my manager asking for holiday next week.', $enhanced);
    }

    public function testAnAnswerThatIsNoRewriteIsNoEnhancement(): void
    {
        $ai = $this->createStub(AiFacade::class);
        // The tools:enhance prompt answers this token when there is nothing to rewrite.
        $ai->method('chat')->willReturn(['content' => '__UNENHANCEABLE__']);

        self::assertNull($this->enhancer($ai)->enhance($this->user(), 'mail boss holiday'));
    }

    public function testWithoutTheEnhancePromptNothingIsAsked(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::never())->method('chat');

        $this->expectException(EnhancePromptMissingException::class);
        $this->enhancer($ai, prompt: false)->enhance($this->user(), 'mail boss holiday');
    }

    private function enhancer(AiFacade $ai, bool $prompt = true): InputEnhancer
    {
        $prompts = $this->createStub(PromptService::class);
        $prompts->method('getPromptWithMetadata')->willReturn($prompt ? ['prompt' => (new Prompt())->setPrompt('Rewrite the text.')] : null);
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(12);
        $models->method('getProviderForModel')->willReturn('groq');
        $models->method('getModelName')->willReturn('llama');

        return new InputEnhancer($prompts, $models, $ai, new NullLogger());
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }
}
