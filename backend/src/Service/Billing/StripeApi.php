<?php

declare(strict_types=1);

namespace App\Service\Billing;

use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Installs the shared HTTP client on Stripe and applies the secret key.
 * A test that already installed its own client is left alone.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'boot', priority: 2048)]
#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'boot', priority: 2048)]
final class StripeApi
{
    private static ?HttpClientInterface $http = null;

    public function __construct(HttpClientInterface $http)
    {
        self::$http = $http;
    }

    public function boot(): void
    {
        self::install();
    }

    public static function activate(string $key): void
    {
        Stripe::setApiKey($key);
        self::install();
    }

    private static function install(): void
    {
        if (null === self::$http) {
            return;
        }
        $current = ApiRequestor::httpClient();
        if ($current instanceof StripeEgressClient || !$current instanceof CurlClient) {
            return;
        }
        ApiRequestor::setHttpClient(new StripeEgressClient(self::$http));
    }
}
