<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

/**
 * Refuses to start a run that would call a paid provider unless the caller
 * said so. The stack's built-in test models have negative ids; every other
 * model is treated as one that costs money.
 */
final class SpendGuard
{
    /** The capabilities a web chat turn can call before and after the answer. */
    private const CAPABILITIES = ['CHAT', 'SORT', 'MEM', 'ANALYZE', 'PLAN', 'TOOLS', 'SUMMARIZE'];

    /**
     * @param array<string, int> $defaultModels capability => model id of the account
     */
    public static function check(array $defaultModels, bool $live): void
    {
        if ($live) {
            return;
        }
        $paid = [];
        foreach (self::CAPABILITIES as $capability) {
            if (($defaultModels[$capability] ?? -1) > 0) {
                $paid[] = $capability.' (model '.$defaultModels[$capability].')';
            }
        }
        if ([] !== $paid) {
            throw new \RuntimeException('This account answers with models that cost money: '.implode(', ', $paid).'. Pass --live to spend its budget. Nothing was sent.');
        }
    }
}
