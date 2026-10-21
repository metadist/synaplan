<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health;

use App\AI\Health\ModelAlternatives;
use App\AI\Health\ModelHealthSnapshotAlternatives;
use App\AI\Health\ModelUnavailabilityWindow;
use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\Config\LayeredConfigResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ModelHealthSnapshotAlternativesTest extends TestCase
{
    public function testOnlyAProblemOrARepeatedOutageGetsAlternatives(): void
    {
        $healthy = $this->row(1, false);
        $offline = $this->row(2, true);
        $flaky = $this->row(3, false);

        $window = new ModelUnavailabilityWindow(new ArrayAdapter(), new NullLogger());
        $now = time();
        $window->record(3, $now);
        $window->record(3, $now);
        $window->record(3, $now);

        $offlineModel = $this->model(2, 'gpt 5.5');
        $flakyModel = $this->model(3, 'slow');
        $models = $this->createStub(ModelRepository::class);
        $models->method('find')->willReturnCallback(static fn (int $id): ?Model => match ($id) {
            2 => $offlineModel,
            3 => $flakyModel,
            default => null,
        });

        $alternatives = $this->createMock(ModelAlternatives::class);
        $alternatives->expects(self::exactly(2))->method('forModel')->willReturn([
            ['id' => 9, 'name' => 'gpt 6 sol', 'service' => 'OpenAI'],
        ]);
        $alternatives->method('defaultCapability')->willReturn('CHAT');

        $snapshot = (new ModelHealthSnapshotAlternatives($models, $window, $alternatives))->annotate([
            'providers' => [[
                'name' => 'openai',
                'models' => [$healthy, $offline, $flaky],
            ]],
        ]);

        $rows = $snapshot['providers'][0]['models'];
        self::assertSame(0, $rows[0]['unavailableCount']);
        self::assertSame([], $rows[0]['alternatives']);
        self::assertNull($rows[0]['defaultCapability']);

        self::assertSame(0, $rows[1]['unavailableCount']);
        self::assertSame('gpt 6 sol', $rows[1]['alternatives'][0]['name']);
        self::assertSame('CHAT', $rows[1]['defaultCapability']);

        self::assertSame(3, $rows[2]['unavailableCount']);
        self::assertCount(1, $rows[2]['alternatives']);
    }

    public function testALockedDefaultHidesTheButtonButStillListsAlternatives(): void
    {
        $window = new ModelUnavailabilityWindow(new ArrayAdapter(), new NullLogger());
        $models = $this->createStub(ModelRepository::class);
        $models->method('find')->willReturn($this->model(2, 'gpt 5.5'));

        $alternatives = $this->createStub(ModelAlternatives::class);
        $alternatives->method('forModel')->willReturn([
            ['id' => 9, 'name' => 'gpt 6 sol', 'service' => 'OpenAI'],
        ]);
        $alternatives->method('defaultCapability')->willReturn('CHAT');

        $resolver = $this->createStub(LayeredConfigResolver::class);
        $resolver->method('isLocked')->willReturn(true);

        $snapshot = (new ModelHealthSnapshotAlternatives($models, $window, $alternatives, $resolver))
            ->annotate([
                'providers' => [['name' => 'openai', 'models' => [$this->row(2, true)]]],
            ], 4);

        $row = $snapshot['providers'][0]['models'][0];
        self::assertNull($row['defaultCapability']);
        self::assertSame('gpt 6 sol', $row['alternatives'][0]['name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, bool $needsAttention): array
    {
        return ['id' => $id, 'needsAttention' => $needsAttention, 'name' => 'model '.$id];
    }

    private function model(int $id, string $name): Model
    {
        $model = (new Model())->setName($name)->setService('OpenAI')->setTag('chat')->setActive(1);
        $idProperty = new \ReflectionProperty(Model::class, 'id');
        $idProperty->setValue($model, $id);

        return $model;
    }
}
