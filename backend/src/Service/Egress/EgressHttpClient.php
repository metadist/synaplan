<?php

declare(strict_types=1);

namespace App\Service\Egress;

use App\Observability\EventRingStore;
use App\Observability\RequestTimelineContext;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The one outbound HTTP client. In `off` mode it is a pass-through, so a
 * request is unchanged unless `EGRESS_PROXY` is set. In `report` and
 * `enforce` it checks every hop, follows redirects itself, and pins a typed
 * URL to the guard's address when a proxy would otherwise resolve the name
 * again.
 */
#[AsDecorator(decorates: 'http_client')]
final class EgressHttpClient implements HttpClientInterface
{
    private const DEFAULT_REDIRECTS = 20;

    public function __construct(
        #[AutowireDecorated]
        private readonly HttpClientInterface $inner,
        private readonly EgressPolicy $policy,
        private readonly EgressConfig $config,
        private readonly EgressTypedUrlGuard $typedUrls,
        private readonly EgressMetrics $metrics,
        private readonly ?EventRingStore $events = null,
        private readonly ?RequestTimelineContext $timeline = null,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = $this->applyProxy($options);
        if (EgressMode::Off === $this->config->mode()) {
            return $this->inner->request($method, $url, $options);
        }

        $limit = $this->redirectLimit($options);
        $hopUrl = $url;
        $hopMethod = $method;
        $hopOptions = $options;
        $started = hrtime(true);

        for ($hop = 0; $hop <= $limit; ++$hop) {
            $decision = $this->policy->decide($hopUrl, $hopOptions);
            if (!$decision->allowed && EgressMode::Enforce === $this->config->mode()) {
                $this->observe($hopUrl, $hopOptions, 'refused', $started, 0);
                $destination = EgressDestination::fromUrl($hopUrl);
                throw new EgressRefusedException($destination ?? new EgressDestination('https', 'invalid', 443));
            }
            if (!$decision->allowed) {
                $this->wouldBlock($hopUrl);
            }

            $send = $this->prepare($hopUrl, $hopOptions);
            $response = $this->inner->request($hopMethod, $hopUrl, $send);
            $status = $response->getStatusCode();
            $outcome = $decision->allowed ? 'allowed' : 'would_block';
            if ($status < 300 || $status >= 400 || 0 === $limit || $hop === $limit) {
                $this->observe($hopUrl, $hopOptions, $outcome, $started, $status);

                return $response;
            }

            $location = $this->location($response);
            if (null === $location) {
                $this->observe($hopUrl, $hopOptions, $outcome, $started, $status);

                return $response;
            }
            $response->cancel();
            $next = $this->resolveLocation($hopUrl, $location);
            $hopOptions = $this->nextHop($hopUrl, $next, $status, $hopOptions);
            $hopMethod = \in_array($status, [307, 308], true) ? $hopMethod : 'GET';
            $hopUrl = $next;
        }

        throw new EgressRefusedException(new EgressDestination('https', 'redirect', 443));
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = new self(
            $this->inner->withOptions($options),
            $this->policy,
            $this->config,
            $this->typedUrls,
            $this->metrics,
            $this->events,
            $this->timeline,
        );

        return $clone;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function applyProxy(array $options): array
    {
        $proxy = $this->config->proxy();
        if (null !== $proxy && !isset($options['proxy'])) {
            $options['proxy'] = $proxy;
        }
        $noProxy = $this->config->noProxy();
        if (null !== $noProxy && !isset($options['no_proxy'])) {
            $options['no_proxy'] = $noProxy;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function prepare(string $url, array $options): array
    {
        $typed = EgressCall::isTyped($options);
        $options['max_redirects'] = 0;
        $options = EgressCall::strip($options);
        if ($typed) {
            $options = $this->pinThroughProxy($url, $options);
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function pinThroughProxy(string $url, array $options): array
    {
        if (!$this->proxyInUse($options)) {
            return $options;
        }
        $destination = EgressDestination::fromUrl($url);
        if (null === $destination || false !== filter_var($destination->host, \FILTER_VALIDATE_IP)) {
            return $options;
        }
        $pins = $this->typedUrls->pins($destination->host);
        if ([] === $pins || !\defined('CURLOPT_CONNECT_TO')) {
            return $options;
        }
        $extra = \is_array($options['extra'] ?? null) ? $options['extra'] : [];
        $curl = \is_array($extra['curl'] ?? null) ? $extra['curl'] : [];
        $curl[\CURLOPT_CONNECT_TO] = [$destination->host.':'.$destination->port.':'.$pins[0].':'.$destination->port];
        $extra['curl'] = $curl;
        $options['extra'] = $extra;

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function proxyInUse(array $options): bool
    {
        if (isset($options['proxy']) && \is_string($options['proxy']) && '' !== $options['proxy']) {
            return true;
        }
        if (null !== $this->config->proxy()) {
            return true;
        }
        foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy'] as $name) {
            $value = getenv($name);
            if (\is_string($value) && '' !== trim($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function redirectLimit(array $options): int
    {
        if (!isset($options['max_redirects'])) {
            return self::DEFAULT_REDIRECTS;
        }

        return max(0, (int) $options['max_redirects']);
    }

    private function location(ResponseInterface $response): ?string
    {
        $headers = $response->getHeaders(false);
        $location = $headers['location'][0] ?? null;

        return \is_string($location) && '' !== $location ? $location : null;
    }

    private function resolveLocation(string $current, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }
        $parts = parse_url($current);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $path = \is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        $dir = rtrim(\dirname($path), '/');

        return $origin.$dir.'/'.$location;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function nextHop(string $from, string $to, int $status, array $options): array
    {
        $fromHost = EgressDestination::fromUrl($from)?->host;
        $toHost = EgressDestination::fromUrl($to)?->host;
        if (null !== $fromHost && $fromHost !== $toHost) {
            $headers = \is_array($options['headers'] ?? null) ? $options['headers'] : [];
            $options['headers'] = $this->dropCredentials($headers);
        }
        if (!\in_array($status, [307, 308], true)) {
            unset($options['body'], $options['json']);
        }

        return $options;
    }

    /**
     * @param array<mixed> $headers
     *
     * @return array<mixed>
     */
    private function dropCredentials(array $headers): array
    {
        $kept = [];
        foreach ($headers as $name => $value) {
            if (\is_int($name)) {
                if (\is_string($value) && 1 === preg_match('/^(authorization|cookie)\s*:/i', $value)) {
                    continue;
                }
                $kept[] = $value;
                continue;
            }
            if (\in_array(strtolower((string) $name), ['authorization', 'cookie'], true)) {
                continue;
            }
            $kept[$name] = $value;
        }

        return $kept;
    }

    private function wouldBlock(string $url): void
    {
        $destination = EgressDestination::fromUrl($url);
        $label = $destination?->label() ?? 'invalid';
        $this->events?->record([
            'level' => 'warning',
            'channel' => 'egress',
            'event' => 'egress.would_block',
            'message' => $label.' '.$this->caller(),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function observe(string $url, array $options, string $outcome, int $started, int $status): void
    {
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $this->metrics->record($this->policy->metricDestination($url, $options), $outcome, $ms);
        if (null !== $this->timeline && $this->timeline->isStarted()) {
            $this->timeline->recordEgress($url, 'refused' === $outcome ? 0 : $status, $ms);
        }
    }

    private function caller(): string
    {
        foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            if ('' !== $class && str_starts_with($class, 'App\\') && !str_starts_with($class, 'App\\Service\\Egress\\')) {
                return $class;
            }
        }

        return 'unknown';
    }
}
