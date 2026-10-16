<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\DesktopDevice;
use App\Entity\DesktopJob;
use App\Repository\DesktopDeviceRepository;
use App\Repository\DesktopJobRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The user's paired computers: their jobs first, then the devices. Each
 * device's API key goes with {@see AccessCredentialsEraser}, so the desktop
 * app gets a 401 on its next check-in and deletes its local key.
 */
#[AsTaggedItem(priority: 185)]
final readonly class DesktopEraser implements UserDataEraserInterface
{
    public function __construct(
        private DesktopJobRepository $jobs,
        private DesktopDeviceRepository $devices,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->jobs->deleteByOwner($erasure->userId);
        $this->devices->deleteByOwner($erasure->userId);
    }

    public static function covers(): array
    {
        return [DesktopJob::class, DesktopDevice::class];
    }
}
