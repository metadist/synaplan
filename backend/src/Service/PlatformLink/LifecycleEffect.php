<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ExternalIdentity;

/**
 * What a lifecycle event does to watches. CF5.1 replaces the no-op.
 */
interface LifecycleEffect
{
    public function onDeleted(ExternalIdentity $identity): void;

    public function onPaused(ExternalIdentity $identity): void;

    public function onResumed(ExternalIdentity $identity): void;
}
