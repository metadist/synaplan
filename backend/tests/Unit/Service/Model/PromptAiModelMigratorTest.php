<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Model;

use App\Entity\Config;
use App\Entity\Model;
use App\Entity\Prompt;
use App\Entity\PromptMeta;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Service\Model\PromptAiModelMigrator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PromptAiModelMigratorTest extends TestCase
{
    public function testDryRunReportsWouldMigrateWhenUserHasNoDefault(): void
    {
        $meta = $this->createPromptMeta(10, 42);
        $prompt = $this->createPrompt(10, 7, 'sales');
        $model = $this->createModel(42, 'CHAT');

        $promptMetaRepo = $this->createMock(PromptMetaRepository::class);
        $promptMetaRepo->method('findBy')->willReturn([$meta]);

        $promptRepo = $this->createMock(PromptRepository::class);
        $promptRepo->method('find')->with(10)->willReturn($prompt);

        $modelRepo = $this->createMock(ModelRepository::class);
        $modelRepo->method('find')->with(42)->willReturn($model);

        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $migrator = new PromptAiModelMigrator(
            $promptMetaRepo,
            $promptRepo,
            $modelRepo,
            $configRepo,
            $em,
        );

        $result = $migrator->migrate(false);

        self::assertSame(1, $result['migrated']);
        self::assertSame('would_migrate', $result['details'][0]['action']);
        self::assertSame('CHAT', $result['details'][0]['capability']);
    }

    public function testApplySkipsWhenUserDefaultAlreadyExists(): void
    {
        $meta = $this->createPromptMeta(11, 99);
        $prompt = $this->createPrompt(11, 3, 'support');
        $model = $this->createModel(99, 'ANALYZE');
        $existing = new Config();

        $promptMetaRepo = $this->createMock(PromptMetaRepository::class);
        $promptMetaRepo->method('findBy')->willReturn([$meta]);

        $promptRepo = $this->createMock(PromptRepository::class);
        $promptRepo->method('find')->with(11)->willReturn($prompt);

        $modelRepo = $this->createMock(ModelRepository::class);
        $modelRepo->method('find')->with(99)->willReturn($model);

        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($meta);
        $em->expects($this->once())->method('flush');

        $migrator = new PromptAiModelMigrator(
            $promptMetaRepo,
            $promptRepo,
            $modelRepo,
            $configRepo,
            $em,
        );

        $result = $migrator->migrate(true);

        self::assertSame(0, $result['migrated']);
        self::assertSame(1, $result['cleared']);
        self::assertSame('0', $meta->getMetaValue());
        self::assertSame('skipped_user_default_exists', $result['details'][0]['action']);
    }

    private function createPromptMeta(int $promptId, int $modelId): PromptMeta
    {
        $meta = new PromptMeta();
        $meta->setPromptId($promptId);
        $meta->setMetaKey('aiModel');
        $meta->setMetaValue((string) $modelId);

        return $meta;
    }

    private function createPrompt(int $id, int $ownerId, string $topic): Prompt
    {
        $prompt = new Prompt();
        $reflection = new \ReflectionClass($prompt);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($prompt, $id);
        $prompt->setOwnerId($ownerId);
        $prompt->setTopic($topic);

        return $prompt;
    }

    private function createModel(int $id, string $tag): Model
    {
        $model = new Model();
        $reflection = new \ReflectionClass($model);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($model, $id);
        $model->setTag($tag);
        $model->setActive(1);

        return $model;
    }
}
