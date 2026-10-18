<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Memory\PendingMemoryExtraction;
use App\Service\Message\Handler\Media\MediaTurnMemory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class MediaTurnMemoryTest extends TestCase
{
    /** @var list<array{bool, bool, bool}> */
    private array $built = [];

    private int $userLookups = 0;

    public function testAPromptIsExtractedLikeAChatTurn(): void
    {
        $this->dispatch('I love dogs, draw one', []);

        self::assertSame([[false, false, false]], $this->built);
        self::assertSame(1, $this->userLookups);
    }

    public function testTheRequestAndTheUserCanTurnItOff(): void
    {
        $this->dispatch('Draw a fox', ['channel' => 'WIDGET']);
        $this->dispatch('Draw a fox', ['incognito' => true]);
        $this->dispatch('Draw a fox', [], memoriesEnabled: false);

        self::assertSame([[true, false, false], [false, false, true], [false, true, false]], $this->built);
        self::assertSame(1, $this->userLookups, 'Only a turn the request leaves on reads the user setting.');
    }

    public function testAnEmptyPromptQueuesNothing(): void
    {
        $this->dispatch('   ', []);

        self::assertSame([], $this->built);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function dispatch(string $text, array $options, bool $memoriesEnabled = true): void
    {
        $memory = $this->createStub(PendingMemoryExtraction::class);
        $memory->method('build')->willReturnCallback(function (Message $message, string $answer, array $thread, array $memories, bool $disabledByRequest, bool $disabledByUser, array $context = [], bool $incognito = false): null {
            $this->built[] = [$disabledByRequest, $disabledByUser, $incognito];

            return null;
        });
        $user = $this->createStub(User::class);
        $user->method('isMemoriesEnabled')->willReturn($memoriesEnabled);
        $users = $this->createStub(EntityRepository::class);
        $users->method('find')->willReturnCallback(function () use ($user): User {
            ++$this->userLookups;

            return $user;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getUserId')->willReturn(7);

        (new MediaTurnMemory($memory, $em))->dispatch($message, [], [], $options);
    }
}
