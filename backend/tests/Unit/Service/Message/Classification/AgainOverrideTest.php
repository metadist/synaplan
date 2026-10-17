<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Repository\MessageMetaRepository;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Classification\AgainOverride;
use App\Service\ModelConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AgainOverrideTest extends TestCase
{
    private int $metaReads = 0;

    public function testAMessageWithoutAPickIsLeftToTheCascade(): void
    {
        self::assertNull($this->route([]));
    }

    public function testAPickedModelSetsTheTopicByItsTag(): void
    {
        self::assertSame(
            [['mediamaker', 'image_generation', 77], ['legal-review', 'chat', 42]],
            [
                $this->pick($this->route(['MODEL_ID' => '77'], 'text2pic')),
                $this->pick($this->route(['MODEL_ID' => '42'], 'chat', topic: 'legal-review')),
            ],
        );
    }

    public function testAPickedPromptIsTheTopicWithThePickedModel(): void
    {
        self::assertSame(['legal-review', 'chat', 42], $this->pick($this->route(['PROMPTID' => 'legal-review', 'MODEL_ID' => '42'], 'chat')));
        self::assertSame('prompt_override', $this->route(['PROMPTID' => 'legal-review'])['source'] ?? null);
    }

    public function testTheSortersOwnPromptIsNoPick(): void
    {
        self::assertNull($this->route(['PROMPTID' => 'tools:sort']));
    }

    public function testATransientMessageReadsNoMeta(): void
    {
        self::assertNull($this->route(['PROMPTID' => 'legal-review'], messageId: null));
        self::assertSame(0, $this->metaReads);
    }

    /**
     * @param array<string, mixed>|null $route
     *
     * @return list<mixed>
     */
    private function pick(?array $route): array
    {
        return [$route['topic'] ?? null, $route['intent'] ?? null, $route['model_id'] ?? null];
    }

    /**
     * @param array<string, string> $meta
     *
     * @return array<string, mixed>|null
     */
    private function route(array $meta, string $modelTag = 'chat', string $topic = '', ?int $messageId = 5): ?array
    {
        $repository = $this->createStub(MessageMetaRepository::class);
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria) use ($meta): ?MessageMeta {
            ++$this->metaReads;
            if (!isset($meta[$criteria['metaKey']])) {
                return null;
            }
            $row = $this->createStub(MessageMeta::class);
            $row->method('getMetaValue')->willReturn($meta[$criteria['metaKey']]);

            return $row;
        });
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getModelTag')->willReturn($modelTag);
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn($messageId);
        $message->method('getTopic')->willReturn($topic);
        $message->method('getLanguage')->willReturn('en');

        return (new AgainOverride($repository, $models, new TopicIntentRule(new SystemCapabilityRegistry()), new NullLogger()))->route($message);
    }
}
