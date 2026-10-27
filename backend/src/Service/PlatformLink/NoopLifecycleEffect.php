<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ExternalIdentity;

/**
 * Watches do not exist yet. The hook is here so CF5.1 can pause and stop them.
 */
final class NoopLifecycleEffect implements LifecycleEffect
{
    public function onDeleted(ExternalIdentity $identity): void
    {
    }

    public function onPaused(ExternalIdentity $identity): void
    {
    }

    public function onResumed(ExternalIdentity $identity): void
    {
    }
}
