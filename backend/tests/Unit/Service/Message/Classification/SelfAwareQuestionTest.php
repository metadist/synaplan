<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Repository\ConfigRepository;
use App\Service\Message\Classification\SelfAwareQuestion;
use App\Service\SelfAware\SelfAwareConfig;
use PHPUnit\Framework\TestCase;

final class SelfAwareQuestionTest extends TestCase
{
    public function testAQuestionAboutTheProductInAnyOfItsLanguages(): void
    {
        $question = new SelfAwareQuestion($this->config(null));

        self::assertSame(
            [true, true, true, true, true, false],
            [
                $question->matches('what can you do for me?', 10),
                $question->matches('Was kannst du alles?', 10),
                $question->matches('¿qué puedes hacer?', 10),
                $question->matches('Ne yapabilirsin?', 10),
                $question->matches('Is Synaplan open source?', 10),
                $question->matches('tell me a joke', 10),
            ],
        );
    }

    public function testWithoutTheFeatureNothingIsAQuestionAboutTheProduct(): void
    {
        self::assertFalse((new SelfAwareQuestion())->matches('what can you do?', 10));
        self::assertFalse((new SelfAwareQuestion($this->config('0')))->matches('what can you do?', 10));
    }

    private function config(?string $enabled): SelfAwareConfig
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getValue')->willReturn($enabled);

        return new SelfAwareConfig($repository);
    }
}
