<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\InboundEmailHandler;
use App\Repository\InboundEmailHandlerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 110)]
final readonly class InboxEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private InboundEmailHandlerRepository $handlers,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        foreach ($this->handlers->findBy(['userId' => $erasure->userId]) as $handler) {
            $this->em->remove($handler);
        }
    }

    public static function covers(): array
    {
        return [InboundEmailHandler::class];
    }
}
