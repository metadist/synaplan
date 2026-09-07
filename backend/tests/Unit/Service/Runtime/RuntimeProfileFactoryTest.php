<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Runtime;

use App\Entity\Prompt;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\Runtime\RuntimeProfileFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RuntimeProfileFactoryTest extends TestCase
{
    private ModelConfigService&MockObject $modelConfig;
    private PromptService&MockObject $promptService;
    private RuntimeProfileFactory $factory;

    protected function setUp(): void
    {
        $this->modelConfig = $this->createMock(ModelConfigService::class);
        $this->promptService = $this->createMock(PromptService::class);
        $this->factory = new RuntimeProfileFactory($this->modelConfig, $this->promptService);
    }

    public function testDefaultsMatchClassificationDerivedValues(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $prompt = new Prompt();
        $prompt->setOwnerId(5);
        $prompt->setTopic('legal-review');
        $prompt->setPrompt('You review contracts.');

        $this->promptService->method('getPromptWithMetadata')
            ->with('legal-review', 5, 'en')
            ->willReturn([
                'prompt' => $prompt,
                'metadata' => [
                    'aiModel' => -1,
                    'tool_internet' => true,
                ],
            ]);
        $this->modelConfig->method('getDefaultModel')->with('CHAT', 5)->willReturn(42);

        $profile = $this->factory->forUserDefaults($user, [
            'topic' => 'legal-review',
            'language' => 'en',
        ]);

        self::assertSame('legal-review', $profile->promptTopic);
        self::assertSame('You review contracts.', $profile->systemPrompt);
        self::assertSame(42, $profile->modelIds['chat']);
        self::assertSame('TASKPROMPT:legal-review', $profile->primaryRagGroupKey());
        self::assertTrue($profile->toolFlags['tool_internet']);
        self::assertNull($profile->agentId);
        self::assertSame([], $profile->notes);
    }

    public function testGeneralTopicHasNoDefaultRagScope(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);
        $this->promptService->method('getPromptWithMetadata')->willReturn(null);
        $this->modelConfig->method('getDefaultModel')->willReturn(1);

        $profile = $this->factory->forUserDefaults($user, ['topic' => 'general', 'language' => 'en']);

        self::assertNull($profile->primaryRagGroupKey());
    }
}
