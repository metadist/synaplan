<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MarketingNews;

use App\Repository\ConfigRepository;
use App\Service\MarketingNews\MarketingNewsConfig;
use App\Service\MarketingNews\MarketingNewsFeedService;
use App\Service\MarketingNews\RssFeedParser;
use App\Service\Security\HostResolver;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MarketingNewsFeedServiceTest extends TestCase
{
    private const FEED_URL = 'https://news.example/feed.xml';

    /** @var list<string> */
    private array $requested = [];

    public function testAFeedRedirectToAnInternalAddressIsNotFollowed(): void
    {
        $service = $this->service([
            self::FEED_URL => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://10.0.0.5/feed.xml']]),
        ]);

        self::assertSame([], $service->getLandingItems('en'));
        self::assertSame([self::FEED_URL], $this->requested);
    }

    public function testAFeedRedirectToAPublicAddressIsFollowed(): void
    {
        $service = $this->service([
            self::FEED_URL => new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => 'https://www.news.example/feed.xml']]),
            'https://www.news.example/feed.xml' => new MockResponse('<rss version="2.0"><channel></channel></rss>'),
        ]);

        $service->getLandingItems('en');

        self::assertSame([self::FEED_URL, 'https://www.news.example/feed.xml'], $this->requested);
    }

    public function testACoverImageRedirectIsNotFollowed(): void
    {
        $imageUrl = 'https://news.example/cover.png';
        $service = $this->service([
            $imageUrl => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://elsewhere.example/cover.png']]),
        ]);

        self::assertNull($service->fetchImage($imageUrl));
        self::assertSame([$imageUrl], $this->requested);
    }

    /**
     * @param array<string, MockResponse> $responses by URL
     */
    private function service(array $responses): MarketingNewsFeedService
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $setting): string => MarketingNewsConfig::KEY_ENABLED === $setting ? '1' : self::FEED_URL,
        );

        $http = new MockHttpClient(function (string $method, string $url) use ($responses): MockResponse {
            $this->requested[] = $url;

            return $responses[$url] ?? new MockResponse('', ['http_code' => 404]);
        });

        $resolver = new class implements HostResolver {
            public function addresses(string $host): array
            {
                return str_ends_with($host, '.example') ? ['93.184.216.34'] : [];
            }
        };

        return new MarketingNewsFeedService(
            new MarketingNewsConfig($config),
            $http,
            new ArrayAdapter(),
            new RssFeedParser(),
            new NullLogger(),
            new SsrfGuard($resolver),
        );
    }
}
