<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Digest;

use App\Entity\MessageDigest;
use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Service\Digest\MessageReferenceResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MessageReferenceResolverTest extends TestCase
{
    private const USER_ID = 7;

    private MessageDigestRepository&MockObject $digestRepository;
    private MessageReferenceResolver $resolver;
    private User $user;

    protected function setUp(): void
    {
        $this->digestRepository = $this->createMock(MessageDigestRepository::class);
        $this->resolver = new MessageReferenceResolver($this->digestRepository, new NullLogger());

        $this->user = new User();
        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setValue($this->user, self::USER_ID);
    }

    public function testTextWithoutTagsIsUntouchedAndSkipsTheRepository(): void
    {
        $this->digestRepository->expects($this->never())->method('findActiveByUserAndMessageIds');

        $text = 'Plain answer, even with [Memory:42] tags.';

        self::assertSame($text, $this->resolver->resolveMessageTags($text, $this->user));
    }

    public function testKnownTagIsReplacedWithQuotedDigestTitle(): void
    {
        $this->digestRepository->expects($this->once())
            ->method('findActiveByUserAndMessageIds')
            ->with(self::USER_ID, [1234])
            ->willReturn([$this->digest(1234, 'office rent letter about the increase')]);

        $resolved = $this->resolver->resolveMessageTags('See [Message:1234] for details.', $this->user);

        self::assertSame('See ("office rent letter about the increase") for details.', $resolved);
    }

    public function testUnknownTagIsStripped(): void
    {
        $this->digestRepository->method('findActiveByUserAndMessageIds')->willReturn([]);

        $resolved = $this->resolver->resolveMessageTags('See [Message:999] for details.', $this->user);

        self::assertSame('See  for details.', $resolved);
    }

    public function testInactiveDigestIsStripped(): void
    {
        // The repository only returns ACTIVE rows, so a soft-deleted digest
        // arrives here as a missing id.
        $this->digestRepository->method('findActiveByUserAndMessageIds')->willReturn([]);

        $resolved = $this->resolver->resolveMessageTags('Ref [Message:1].', $this->user);

        self::assertSame('Ref .', $resolved);
    }

    public function testEveryTagOfAResponseIsResolvedInASingleQuery(): void
    {
        $this->digestRepository->expects($this->once())
            ->method('findActiveByUserAndMessageIds')
            ->with(self::USER_ID, [1234, 5678])
            ->willReturn([
                $this->digest(1234, 'the letter'),
                $this->digest(5678, 'the invoice'),
            ]);

        $resolved = $this->resolver->resolveMessageTags(
            '[Message:1234], again [Message:1234], and [Message:5678].',
            $this->user
        );

        self::assertSame('("the letter"), again ("the letter"), and ("the invoice").', $resolved);
    }

    public function testTagCountBeyondTheLimitIsCappedAndTheExtrasAreStripped(): void
    {
        $ids = range(1, 120);
        $text = implode(' ', array_map(static fn (int $id): string => "[Message:{$id}]", $ids));

        $this->digestRepository->expects($this->once())
            ->method('findActiveByUserAndMessageIds')
            ->with(self::USER_ID, self::callback(static fn (array $requested): bool => 100 === count($requested)))
            ->willReturn([$this->digest(1, 'the letter')]);

        $resolved = $this->resolver->resolveMessageTags($text, $this->user);

        self::assertStringStartsWith('("the letter")', $resolved);
        self::assertStringNotContainsString('[Message:', $resolved);
    }

    public function testToleratesWhitespaceAndCaseVariants(): void
    {
        $this->digestRepository->method('findActiveByUserAndMessageIds')
            ->willReturn([$this->digest(12, 'the letter')]);

        $resolved = $this->resolver->resolveMessageTags('Ref [message : 12].', $this->user);

        self::assertSame('Ref ("the letter").', $resolved);
    }

    private function digest(int $messageId, string $title): MessageDigest
    {
        $digest = new MessageDigest();
        $digest->setMessageId($messageId);
        $digest->setTitle($title);
        $digest->setActive(true);

        return $digest;
    }
}
