<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Model;

use App\AI\Health\ModelAlternatives;
use App\AI\Health\ModelUnavailabilityWindow;
use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\Model\DefaultModelChangeOffer;
use App\Service\Model\ModelDefaultsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DefaultModelChangeOfferTest extends TestCase
{
    public function testNothingIsOfferedUntilTheModelHasFailedMoreThanTwice(): void
    {
        $window = $this->window();
        $now = time();
        $window->record(7, $now);
        $window->record(7, $now);

        $offers = $this->offer($window)->forUser(4);

        self::assertSame([], $offers['offers']);
    }

    public function testARepeatedOutageOffersAlternativesAndSkipsLockedAndEmbeddingDefaults(): void
    {
        $window = $this->window();
        $now = time();
        $window->record(7, $now);
        $window->record(7, $now);
        $window->record(7, $now);
        $window->record(8, $now);
        $window->record(8, $now);
        $window->record(8, $now);

        $offers = $this->offer($window)->forUser(4);

        self::assertCount(1, $offers['offers']);
        self::assertSame('CHAT', $offers['offers'][0]['capability']);
        self::assertSame(7, $offers['offers'][0]['modelId']);
        self::assertSame('gpt 5.5', $offers['offers'][0]['modelName']);
        self::assertSame(3, $offers['offers'][0]['unavailableCount']);
        self::assertSame('gpt 6 sol', $offers['offers'][0]['alternatives'][0]['name']);
    }

    private function window(): ModelUnavailabilityWindow
    {
        return new ModelUnavailabilityWindow(new ArrayAdapter(), new NullLogger());
    }

    private function offer(ModelUnavailabilityWindow $window): DefaultModelChangeOffer
    {
        $defaults = $this->createStub(ModelDefaultsService::class);
        $defaults->method('userDefaults')->willReturn([
            'defaults' => ['CHAT' => 7, 'ANALYZE' => 7, 'VECTORIZE' => 8],
            'locked' => ['CHAT' => false, 'ANALYZE' => true, 'VECTORIZE' => false],
            'sources' => ['CHAT' => 'user', 'ANALYZE' => 'admin', 'VECTORIZE' => 'admin'],
        ]);

        $chat = (new Model())->setName('gpt 5.5')->setService('OpenAI')->setTag('chat')->setActive(1);
        $embed = (new Model())->setName('embed')->setService('Ollama')->setTag('vectorize')->setActive(1);
        $models = $this->createStub(ModelRepository::class);
        $models->method('find')->willReturnCallback(static fn (int $id): ?Model => match ($id) {
            7 => $chat,
            8 => $embed,
            default => null,
        });

        $alternatives = $this->createStub(ModelAlternatives::class);
        $alternatives->method('forModel')->willReturn([
            ['id' => 9, 'name' => 'gpt 6 sol', 'service' => 'OpenAI'],
        ]);

        return new DefaultModelChangeOffer($defaults, $models, $window, $alternatives);
    }
}
