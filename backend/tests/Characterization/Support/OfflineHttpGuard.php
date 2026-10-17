<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use Http\Discovery\Psr18ClientDiscovery;
use Http\Discovery\Strategy\DiscoveryStrategy;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Makes PSR-18 discovery hand out a client that refuses to send and records
 * the attempt. The OpenAI SDK finds its HTTP client through discovery unless
 * one is injected, so a provider path that bypasses the injected SDK factory
 * would reach the network; with the guard installed it is recorded instead.
 */
final class OfflineHttpGuard implements DiscoveryStrategy
{
    /** @var list<string> */
    private static array $attempts = [];

    /** @var list<mixed>|null */
    private static ?array $previous = null;

    public static function install(): void
    {
        self::$attempts = [];
        self::$previous = [...Psr18ClientDiscovery::getStrategies()];
        Psr18ClientDiscovery::prependStrategy(self::class);
        Psr18ClientDiscovery::clearCache();
    }

    public static function uninstall(): void
    {
        if (null !== self::$previous) {
            Psr18ClientDiscovery::setStrategies(self::$previous);
            self::$previous = null;
        }
        Psr18ClientDiscovery::clearCache();
    }

    /**
     * @return list<string> the method and URL of every request that tried to leave
     */
    public static function attempts(): array
    {
        return self::$attempts;
    }

    public static function record(RequestInterface $request): void
    {
        self::$attempts[] = $request->getMethod().' '.$request->getUri();
    }

    /**
     * @return list<array{class: \Closure(): ClientInterface}>
     */
    public static function getCandidates($type): array
    {
        if (ClientInterface::class !== $type) {
            return [];
        }

        return [['class' => static fn (): ClientInterface => new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                OfflineHttpGuard::record($request);

                throw new \RuntimeException('A request tried to leave the process: '.$request->getMethod().' '.$request->getUri());
            }
        }]];
    }
}
