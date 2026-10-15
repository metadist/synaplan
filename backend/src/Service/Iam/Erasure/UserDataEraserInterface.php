<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One domain's share of deleting an account. Erasers run inside the deletion
 * transaction in descending `#[AsTaggedItem(priority: …)]` order — an order
 * that matters, because some repositories delete immediately while entity
 * removals wait for the flush.
 */
#[AutoconfigureTag(self::TAG)]
interface UserDataEraserInterface
{
    public const TAG = 'app.user_data_eraser';

    /**
     * Remove this domain's rows for the user. Work outside the database
     * (vector stores, files, third-party webhooks) is queued with
     * {@see UserErasure::afterCommit()}.
     */
    public function erase(UserErasure $erasure): void;

    /**
     * The entities whose user-owned rows this eraser removes; read by the
     * coverage test, so a new user-owned table cannot be forgotten silently.
     *
     * @return list<class-string>
     */
    public static function covers(): array;
}
