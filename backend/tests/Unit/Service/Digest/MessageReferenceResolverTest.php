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
        $this->digestRepository->expects($this->never())->method('findOneByUserAndMessage');

        $text = 'Plain answer, even with [Memory:42] tags.';

        self::assertSame($text, $this->resolver->resolveMessageTags($text, $this->user));
    }

    public function testKnownTagIsReplacedWithQuotedDigestTitle(): void
    {
        $this->digestRepository->method('findOneByUserAndMessage')
            ->willReturnCallback(fn (int $userId, int $messageId) => (self::USER_ID === $userId && 1234 === $messageId)
                ? $this->digest('office rent letter about the increase')
                : null);

        $resolved = $this->resolver->resolveMessageTags('See [Message:1234] for details.', $this->user);

        self::assertSame('See ("office rent letter about the increase") for details.', $resolved);
    }

    public function testUnknownTagIsStripped(): void
    {
        $this->digestRepository->method('findOneByUserAndMessage')->willReturn(null);

        $resolved = $this->resolver->resolveMessageTags('See [Message:999] for details.', $this->user);

        self::assertSame('See  for details.', $resolved);
    }

    public function testInactiveDigestIsStripped(): void
    {
        $this->digestRepository->method('findOneByUserAndMessage')
            ->willReturn($this->digest('soft-deleted digest', active: false));

        $resolved = $this->resolver->resolveMessageTags('Ref [Message:1].', $this->user);

        self::assertSame('Ref .', $resolved);
    }

    public function testRepeatedTagIsLookedUpOnce(): void
    {
        $this->digestRepository->expects($this->once())
            ->method('findOneByUserAndMessage')
            ->with(self::USER_ID, 1234)
            ->willReturn($this->digest('the letter'));

        $resolved = $this->resolver->resolveMessageTags(
            '[Message:1234] and again [Message:1234].',
            $this->user
        );

        self::assertSame('("the letter") and again ("the letter").', $resolved);
    }

    public function testToleratesWhitespaceAndCaseVariants(): void
    {
        $this->digestRepository->method('findOneByUserAndMessage')
            ->willReturn($this->digest('the letter'));

        $resolved = $this->resolver->resolveMessageTags('Ref [message : 12].', $this->user);

        self::assertSame('Ref ("the letter").', $resolved);
    }

    private function digest(string $title, bool $active = true): MessageDigest
    {
        $digest = new MessageDigest();
        $digest->setTitle($title);
        $digest->setActive($active);

        return $digest;
    }
}
