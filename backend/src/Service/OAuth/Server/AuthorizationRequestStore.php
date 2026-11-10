<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authorization requests a cloud pushed (RFC 9126), kept until the person
 * decides on the consent page or the request runs out. The id is the last
 * part of the request_uri and what the consent page is opened with.
 */
final readonly class AuthorizationRequestStore
{
    public const TTL = 600;
    public const REQUEST_URI_PREFIX = 'urn:ietf:params:oauth:request_uri:';
    public const ID_PATTERN = '[a-f0-9]{48}';

    private const CACHE_PREFIX = 'oauth_authorize_';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array<string, string> $params the checked authorization request
     */
    public function park(array $params): string
    {
        $id = bin2hex(random_bytes(24));
        $item = $this->cache->getItem(self::CACHE_PREFIX.$id);
        $item->set($params);
        $item->expiresAfter(self::TTL);
        $this->cache->save($item);

        return $id;
    }

    /**
     * @return array<string, string>|null
     */
    public function find(string $id): ?array
    {
        if (1 !== preg_match('/^'.self::ID_PATTERN.'$/', $id)) {
            return null;
        }
        $item = $this->cache->getItem(self::CACHE_PREFIX.$id);
        $value = $item->isHit() ? $item->get() : null;
        if (!is_array($value)) {
            return null;
        }

        return array_filter($value, static fn (mixed $v, mixed $k): bool => is_string($v) && is_string($k), \ARRAY_FILTER_USE_BOTH);
    }

    public function forget(string $id): void
    {
        $this->cache->deleteItem(self::CACHE_PREFIX.$id);
    }
}
