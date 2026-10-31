<?php

declare(strict_types=1);

namespace App\Service\Usage;

/**
 * One Synaplan Demo row for the statistics page of the Demo account.
 *
 * A completed chat, embedding or image is already a BUSELOG row. This shape
 * is the other half: a refusal, and a key that was issued. It stores the
 * outcome, the install and the alias. It does not store the prompt, the
 * answer, the key or the address.
 */
final class UsageOutcome
{
    public const ACTION = 'DEMO';

    /**
     * @return array<string, int|string|null>
     */
    public static function refusal(int $userId, int $timestamp, string $code, string $subject, string $installId, string $alias, int $http, string $path): array
    {
        return self::row($userId, $timestamp, self::subject($subject, $alias, $path), 'error', self::code($code), $installId, $alias, $http, $path);
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function issued(int $userId, int $timestamp, string $installId): array
    {
        return self::row($userId, $timestamp, 'key', 'success', '', $installId, '', 200, '/v1/free/keys');
    }

    public static function installId(string $value): string
    {
        $value = strtolower(trim($value));

        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) ? $value : '';
    }

    public static function token(string $value): string
    {
        $value = trim($value);

        return 1 === preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $value) ? $value : '';
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function row(int $userId, int $timestamp, string $subject, string $status, string $error, string $installId, string $alias, int $http, string $path): array
    {
        $installId = self::installId($installId);
        $alias = self::token($alias);
        $path = self::path($path);
        $meta = ['source' => 'OPENAI_API'];
        $http = self::http($http);
        if ($http > 0) {
            $meta['http'] = $http;
        }
        if ('' !== $installId) {
            $meta['install_id'] = $installId;
        }
        if ('' !== $alias) {
            $meta['alias'] = $alias;
        }
        if ('' !== $path) {
            $meta['path'] = $path;
        }

        return [
            'user_id' => $userId,
            'timestamp' => $timestamp,
            'action' => self::ACTION,
            'provider' => 'synaplan',
            'model' => $subject,
            'tokens' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cached_tokens' => 0,
            'cache_creation_tokens' => 0,
            'estimated' => 0,
            'model_id' => null,
            'price_snapshot' => null,
            'cost' => '0.000000',
            'latency' => 0,
            'status' => $status,
            'error' => $error,
            'metadata' => json_encode($meta, JSON_THROW_ON_ERROR),
        ];
    }

    private static function subject(string $subject, string $alias, string $path): string
    {
        $subject = self::token($subject);
        if ('' !== $subject) {
            return $subject;
        }
        $alias = self::token($alias);
        if ('' !== $alias) {
            return $alias;
        }
        $path = self::path($path);

        return '' !== $path ? $path : 'demo';
    }

    private static function code(string $code): string
    {
        $code = strtolower(trim($code));

        return 1 === preg_match('/^freeapi_[a-z0-9_]{1,40}$/', $code) ? $code : 'freeapi_refused';
    }

    private static function path(string $path): string
    {
        $path = trim($path);

        return 1 === preg_match('#^/[A-Za-z0-9._/-]{1,80}$#', $path) ? $path : '';
    }

    private static function http(int $http): int
    {
        return $http >= 100 && $http <= 599 ? $http : 0;
    }
}
