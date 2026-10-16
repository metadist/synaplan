<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

/**
 * Key-and-type shape of a payload.
 *
 * Characterization snapshots compare this instead of live values (timestamps,
 * token text, install-specific flags) so a refactor is guilty only when the
 * contract — which keys exist, and what type they hold — changes.
 */
final class PayloadShape
{
    /**
     * @return array<string, mixed>|string
     */
    public static function of(mixed $value, bool $listElements = false): array|string
    {
        if (!is_array($value)) {
            return get_debug_type($value);
        }

        if (array_is_list($value)) {
            if (!$listElements || [] === $value) {
                return 'list';
            }

            return ['list' => self::of($value[0], true)];
        }

        ksort($value);
        $shaped = [];
        foreach ($value as $key => $item) {
            $shaped[(string) $key] = self::of($item, $listElements);
        }

        return $shaped;
    }
}
