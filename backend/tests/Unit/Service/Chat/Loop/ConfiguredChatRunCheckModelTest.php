<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ConfiguredChatRunCheckModel;
use App\Service\Research\Run\ResearchModelChoice;
use App\Service\Research\Run\ResearchModelLookup;
use PHPUnit\Framework\TestCase;

final class ConfiguredChatRunCheckModelTest extends TestCase
{
    private const CHAT_MODEL = 5;
    private const CHECKER = 9;

    /** @var array<int, ResearchModelChoice> */
    private array $models = [];

    protected function setUp(): void
    {
        $this->models = [
            self::CHAT_MODEL => self::choice(self::CHAT_MODEL, 'claude'),
            self::CHECKER => self::choice(self::CHECKER, 'gpt'),
        ];
    }

    public function testNoCheckingModelIsSetByDefault(): void
    {
        foreach ([null, '-1', '0', 'gpt', '9 '] as $stored) {
            self::assertNull($this->pick($stored), (string) $stored);
        }
    }

    public function testTheModelTheAdminSetChecks(): void
    {
        self::assertSame(self::CHECKER, $this->pick('9')?->id);
    }

    public function testAModelThePersonMayNotUseOrThatIsDownChecksNothing(): void
    {
        $this->models[self::CHECKER] = self::choice(self::CHECKER, 'gpt', allowed: false);
        self::assertNull($this->pick('9'));

        $this->models[self::CHECKER] = self::choice(self::CHECKER, 'gpt', healthy: false);
        self::assertNull($this->pick('9'));
    }

    public function testARetiredModelHandsOverToItsSuccessor(): void
    {
        $this->models[self::CHECKER] = self::choice(self::CHECKER, 'gpt', retired: true, successor: 12);
        $this->models[12] = self::choice(12, 'gpt');

        self::assertSame(12, $this->pick('9')?->id);
    }

    public function testTheChatModelsOwnFamilyIsNoSecondReader(): void
    {
        $this->models[self::CHECKER] = self::choice(self::CHECKER, 'claude');
        self::assertNull($this->pick('9'));

        self::assertNull($this->pick('5'), 'the chat model itself');
    }

    private function pick(?string $stored): ?ResearchModelChoice
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(static fn (int $owner, string $group, string $setting): ?string => 0 === $owner && 'DEFAULTMODEL' === $group && 'VERIFY' === $setting ? $stored : null);
        $lookup = $this->createStub(ResearchModelLookup::class);
        $lookup->method('byId')->willReturnCallback(fn (int $user, int $id): ?ResearchModelChoice => $this->models[$id] ?? null);
        $repository = $this->createStub(ModelRepository::class);
        $repository->method('findChatIdByProvider')->willReturnCallback(static fn (string $provider, string $model): ?int => 'anthropic' === $provider && 'claude-x' === $model ? self::CHAT_MODEL : null);
        $state = new ChatRunState('01JD6Q3Z8Y9X7W6V5T4S3R2Q1P', 7, 11, null, 'anthropic', 'claude-x', 'en', '', [], 1);

        return (new ConfiguredChatRunCheckModel($config, $lookup, $repository))->pick($state);
    }

    private static function choice(int $id, string $family, bool $allowed = true, bool $healthy = true, bool $retired = false, ?int $successor = null): ResearchModelChoice
    {
        return new ResearchModelChoice($id, 'p', $family, $allowed, $healthy, $retired, $successor, null);
    }
}
