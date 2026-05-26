<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\RoutingFeedback;
use App\Repository\ConfigRepository;
use App\Repository\PromptRepository;
use App\Repository\RoutingFeedbackRepository;
use App\Service\Message\FeedbackVerifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FeedbackVerifierTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private RoutingFeedbackRepository&MockObject $feedbackRepository;
    private PromptRepository&MockObject $promptRepository;
    private ConfigRepository&MockObject $configRepository;
    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    private FeedbackVerifier $verifier;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->feedbackRepository = $this->createMock(RoutingFeedbackRepository::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->verifier = new FeedbackVerifier(
            $this->aiFacade,
            $this->feedbackRepository,
            $this->promptRepository,
            $this->configRepository,
            $this->em,
            $this->logger,
        );
    }

    public function testRateLimitRejectsExcessiveFeedback(): void
    {
        $this->feedbackRepository->method('countRecentByUser')->willReturn(5);
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $key): ?string {
                if ('FEEDBACK_RATE_LIMIT_PER_MINUTE' === $key) {
                    return '5';
                }

                return null;
            });

        $result = $this->verifier->submitAndVerify(1, 100, 'general', 'mediamaker', 'Test message');

        $this->assertFalse($result['success']);
        $this->assertSame('rate_limited', $result['status']);
    }

    public function testVerifiedFeedbackIsPersisted(): void
    {
        $this->feedbackRepository->method('countRecentByUser')->willReturn(0);
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $key): ?string {
                if ('FEEDBACK_VERIFICATION_ENABLED' === $key) {
                    return 'true';
                }

                return null;
            });

        $this->promptRepository->method('findByTopic')->willReturn(null);
        $this->aiFacade->method('chat')->willReturn([
            'content' => '{"verified": true, "reason": "Makes sense for image generation"}',
        ]);

        $this->em->expects($this->once())->method('persist')
            ->with($this->callback(function ($entity) {
                return $entity instanceof RoutingFeedback
                    && RoutingFeedback::STATUS_VERIFIED === $entity->getStatus();
            }));
        $this->em->expects($this->once())->method('flush');

        $result = $this->verifier->submitAndVerify(
            1, 100, 'general', 'mediamaker', 'Generate a sunset image'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('verified', $result['status']);
    }

    public function testRejectedFeedbackIsPersisted(): void
    {
        $this->feedbackRepository->method('countRecentByUser')->willReturn(0);
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $key): ?string {
                if ('FEEDBACK_VERIFICATION_ENABLED' === $key) {
                    return 'true';
                }

                return null;
            });

        $this->promptRepository->method('findByTopic')->willReturn(null);
        $this->aiFacade->method('chat')->willReturn([
            'content' => '{"verified": false, "reason": "This is clearly a text chat message"}',
        ]);

        $this->em->expects($this->once())->method('persist')
            ->with($this->callback(function ($entity) {
                return $entity instanceof RoutingFeedback
                    && RoutingFeedback::STATUS_REJECTED === $entity->getStatus();
            }));

        $result = $this->verifier->submitAndVerify(
            1, 100, 'general', 'mediamaker', 'What is the weather today?'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('rejected', $result['status']);
    }

    public function testAutoAcceptsWhenVerificationDisabled(): void
    {
        $this->feedbackRepository->method('countRecentByUser')->willReturn(0);
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $key): ?string {
                if ('FEEDBACK_VERIFICATION_ENABLED' === $key) {
                    return 'false';
                }

                return null;
            });

        $this->em->expects($this->once())->method('persist')
            ->with($this->callback(function ($entity) {
                return $entity instanceof RoutingFeedback
                    && RoutingFeedback::STATUS_VERIFIED === $entity->getStatus();
            }));

        $result = $this->verifier->submitAndVerify(
            1, 100, 'general', 'mediamaker', 'Draw a cat'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('verified', $result['status']);
    }

    public function testAutoAcceptsWhenAiFails(): void
    {
        $this->feedbackRepository->method('countRecentByUser')->willReturn(0);
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $key): ?string {
                if ('FEEDBACK_VERIFICATION_ENABLED' === $key) {
                    return 'true';
                }

                return null;
            });

        $this->promptRepository->method('findByTopic')->willReturn(null);
        $this->aiFacade->method('chat')->willThrowException(new \RuntimeException('API Error'));

        $this->em->expects($this->once())->method('persist')
            ->with($this->callback(function ($entity) {
                return $entity instanceof RoutingFeedback
                    && RoutingFeedback::STATUS_VERIFIED === $entity->getStatus();
            }));

        $result = $this->verifier->submitAndVerify(
            1, 100, 'general', 'mediamaker', 'Paint me a picture'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('verified', $result['status']);
    }
}
