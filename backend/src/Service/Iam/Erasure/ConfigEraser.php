<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 100)]
final readonly class ConfigEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configs,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        foreach ($this->configs->findBy(['ownerId' => $erasure->userId]) as $config) {
            $this->em->remove($config);
        }
    }

    public static function covers(): array
    {
        return [Config::class];
    }
}
