<?php

declare(strict_types=1);

namespace App\Service\Compute\Contract;

use App\Service\Compute\ComputeRefusedException;

/**
 * Protocol-1 JSON helper: unknown fields are a hard refusal.
 */
final class ComputeJson
{
    /**
     * @param list<string> $allowed
     *
     * @return array<string, mixed>
     */
    public static function decodeObject(string $json, array $allowed): array
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ComputeRefusedException('invalid_json', $e->getMessage());
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new ComputeRefusedException('invalid_json', 'Expected a JSON object');
        }

        return self::assertKeys($data, $allowed);
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<array<string, mixed>>
     */
    public static function decodeObjectList(string $json, array $allowed): array
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ComputeRefusedException('invalid_json', $e->getMessage());
        }
        if (!is_array($data) || !array_is_list($data)) {
            throw new ComputeRefusedException('invalid_json', 'Expected a JSON array');
        }
        $out = [];
        foreach ($data as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new ComputeRefusedException('invalid_json', 'Expected object rows');
            }
            $out[] = self::assertKeys($row, $allowed);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $allowed
     *
     * @return array<string, mixed>
     */
    public static function assertKeys(array $data, array $allowed): array
    {
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ([] !== $unknown) {
            throw new ComputeRefusedException('unknown_field', 'Unknown field: '.$unknown[0]);
        }

        return $data;
    }
}
