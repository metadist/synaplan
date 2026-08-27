<?php

declare(strict_types=1);

namespace App\Service\Setup;

/**
 * BCONFIG coordinates of the first-run setup flag.
 *
 * Deliberately NOT seeded: {@see \App\Seed} would set the flag on a fresh
 * install and make the wizard unreachable. The row appears exactly twice —
 * written by the backfill migration on an upgrade, or by
 * {@see SetupStateService::markCompleted()} once setup finished.
 */
final class SetupConstants
{
    public const CONFIG_GROUP = 'SETUP';

    public const KEY_COMPLETED = 'COMPLETED';

    /** Install-wide row; the setup flag has no per-user variant. */
    public const OWNER_ID = 0;
}
