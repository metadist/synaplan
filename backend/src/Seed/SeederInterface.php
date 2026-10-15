<?php

declare(strict_types=1);

namespace App\Seed;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One idempotent step of `app:seed`. Steps run in descending
 * `#[AsTaggedItem(priority: …)]` order, which is their dependency order:
 * models before the defaults that reference them, module gates last.
 */
#[AutoconfigureTag(self::TAG)]
interface SeederInterface
{
    public const TAG = 'app.seeder';

    /** Step name in the `app:seed` output and help, e.g. "models". */
    public function label(): string;

    /** What the step writes, one line for `app:seed --help`. */
    public function description(): string;

    public function seed(): SeedResult;
}
