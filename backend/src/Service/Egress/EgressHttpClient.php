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
 * `enforce` it checks the first hop before a socket opens, returns without
 * waiting for headers, and follows redirects only when the response is read.
 * A typed URL behind a proxy is pinned to an address the guard already
 * accepted. Without that pin the hostname is not sent.
 */
#[AsDecorator(decorates: 'http_client')]
final class EgressHttpClient implements HttpClientInterface
{
    private const DEFAULT_REDIRECTS = 20;

    /**
     * @param array<string, mixed> $defaultOptions
     */
    public function __construct(
        #[AutowireDecorated]
        private readonly HttpClientInterface $inner,
        private readonly EgressPolicy $policy,
        private readonly EgressConfig $config,
        private readonly EgressTypedUrlGuard $typedUrls,
        private readonly EgressMetrics $metrics,
        private readonly ?EventRingStore $events = null,
        private readonly ?RequestTimelineContext $timeline = null,
        private readonly array $defaultOptions = [],
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = $this->mergeOptions($this->defaultOptions, $options);
        $url = $this->absoluteUrl($url, $options);
        $options = $this->applyProxy($options);
        if (EgressMode::Off === $this->config->mode()) {
            return $this->inner->request($method, $url, $options);
        }

        $this->guardHop($url, $options);
        $limit = $this->redirectLimit($options);
        $startedAt = hrtime(true);
        $started = $this->inner->request($method, $url, $this->prepare($url, $options));

        return new EgressPendingResponse(
            function (ResponseInterface $response) use ($method, $url, $options, $limit, $startedAt): ResponseInterface {
                return $this->follow($response, $method, $url, $options, $limit, $startedAt);
            },
            $started,
            $limit,
        );
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $target = $this->streamTarget($responses);
            $alias = $responses instanceof EgressPendingResponse ? [spl_object_id($target) => $responses] : [];

            return new EgressResponseStream($this->inner->stream($target, $timeout), $alias);
        }

        $targets = [];
        $alias = [];
        foreach ($responses as $response) {
            $target = $this->streamTarget($response);
            $targets[] = $target;
            if ($response instanceof EgressPendingResponse) {
                $alias[spl_object_id($target)] = $response;
            }
        }

        return new EgressResponseStream($this->inner->stream($targets, $timeout), $alias);
    }

    public function withOptions(array $options): static
    {
        return new self(
            $this->inner->withOptions($options),
            $this->policy,
            $this->config,
            $this->typedUrls,
            $this->metrics,
            $this->events,
            $this->timeline,
            $this->mergeOptions($this->defaultOptions, $options),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function guardHop(string $url, array $options): void
    {
        $decision = $this->policy->decide($url, $options);
        if ($decision->allowed || EgressMode::Enforce !== $this->config->mode()) {
            return;
        }
        $this->observe($url, $options, 'refused', hrtime(true), 0);
        throw new EgressRefusedException(EgressDestination::fromUrl($url) ?? new EgressDestination('https', 'invalid', 443));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function follow(ResponseInterface $response, string $method, string $url, array $options, int $limit, int $started): ResponseInterface
    {
        for ($hop = 0;; ++$hop) {
            $status = $response->getStatusCode();
            $decision = $this->policy->decide($url, $options);
            $this->observe($url, $options, $decision->allowed ? 'allowed' : 'would_block', $started, $status);
            $location = $status >= 300 && $status < 400 && $hop < $limit ? $this->location($response) : null;
            if (null === $location) {
                return $response;
            }

            $response->cancel();
            $next = $this->resolveLocation($url, $location);
            $nextMethod = $this->redirectedMethod($method, $status);
            $nextOptions = $this->nextHop($method, $status, $url, $next, $options);
            $this->guardHop($next, $nextOptions);
            $method = $nextMethod;
            $url = $next;
            $options = $nextOptions;
            $started = hrtime(true);
            $response = $this->inner->request($method, $url, $this->prepare($url, $options));
        }
    }

    private function streamTarget(ResponseInterface $response): ResponseInterface
    {
        if (!$response instanceof EgressPendingResponse) {
            return $response;
        }

        return $response->redirectsRemaining() > 0 ? $response->settle() : $response->transport();
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function mergeOptions(array $base, array $over): array
    {
        $merged = $over + $base;
        foreach (['headers', 'extra', 'resolve'] as $key) {
            if (isset($base[$key], $over[$key]) && \is_array($base[$key]) && \is_array($over[$key])) {
                $merged[$key] = $over[$key] + $base[$key];
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function absoluteUrl(string $url, array $options): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return $url;
        }
        $base = $options['base_uri'] ?? null;
        if (!\is_string($base) || '' === $base) {
            return $url;
        }
        $path = parse_url($base, \PHP_URL_PATH);
        if (!str_ends_with($base, '/') && (!\is_string($path) || '' === $path)) {
            $base .= '/';
        }

        return $this->resolveLocation($base, $url);
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
        if ($typed) {
            $options = $this->pinThroughProxy($url, $options);
        }

        return EgressCall::strip($options);
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
        $destination = EgressDestination::fromUrl($url) ?? new EgressDestination('https', 'invalid', 443);
        if (false !== filter_var($destination->host, \FILTER_VALIDATE_IP)) {
            return $options;
        }
        $pin = EgressCall::pinnedAddress($options, $destination->host);
        if (null === $pin) {
            $pin = $this->typedUrls->pins($destination->host)[0] ?? null;
        }
        if (!\is_string($pin) || false === filter_var($pin, \FILTER_VALIDATE_IP) || !\defined('CURLOPT_CONNECT_TO')) {
            throw new EgressRefusedException($destination);
        }
        $extra = \is_array($options['extra'] ?? null) ? $options['extra'] : [];
        $curl = \is_array($extra['curl'] ?? null) ? $extra['curl'] : [];
        $address = str_contains($pin, ':') && !str_starts_with($pin, '[') ? '['.$pin.']' : $pin;
        $curl[\CURLOPT_CONNECT_TO] = [$destination->host.':'.$destination->port.':'.$address.':'.$destination->port];
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
        if (!\is_string($location)) {
            return null;
        }
        $location = trim($location);
        if ('' === $location || str_starts_with($location, '#')) {
            return null;
        }
        $hash = strpos($location, '#');

        return false === $hash ? $location : substr($location, 0, $hash);
    }

    private function resolveLocation(string $base, string $ref): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref)) {
            return $this->normalizeAbsolute($ref);
        }
        $parts = parse_url($base);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $ref;
        }
        if (str_starts_with($ref, '//')) {
            return $this->normalizeAbsolute($parts['scheme'].':'.$ref);
        }
        $query = null;
        $split = strpos($ref, '?');
        if (false !== $split) {
            $query = substr($ref, $split);
            $ref = substr($ref, 0, $split);
        }
        $path = \is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        if (str_starts_with($ref, '/')) {
            $path = $this->removeDotSegments($ref);
        } elseif ('' !== $ref) {
            $slash = strrpos($path, '/');
            $dir = false === $slash ? '/' : substr($path, 0, $slash + 1);
            $path = $this->removeDotSegments($dir.$ref);
        }
        if ('' === $path) {
            $path = '/';
        }

        return $this->origin($parts).$path.($query ?? '');
    }

    private function normalizeAbsolute(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $path = $this->removeDotSegments(\is_string($parts['path'] ?? null) ? $parts['path'] : '/');
        if ('' === $path) {
            $path = '/';
        }
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $this->origin($parts).$path.$query;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function origin(array $parts): string
    {
        $host = \is_string($parts['host'] ?? null) ? $parts['host'] : '';
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }
        $scheme = \is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    private function removeDotSegments(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $trailing = str_ends_with($path, '/');
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }
        $joined = ($absolute ? '/' : '').implode('/', $out);
        if ($trailing && !str_ends_with($joined, '/')) {
            $joined .= '/';
        }

        return '' === $joined ? '/' : $joined;
    }

    private function redirectedMethod(string $method, int $status): string
    {
        $method = strtoupper($method);
        if (303 === $status || ('POST' === $method && \in_array($status, [301, 302], true))) {
            return 'HEAD' === $method ? 'HEAD' : 'GET';
        }

        return $method;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function nextHop(string $method, int $status, string $from, string $to, array $options): array
    {
        if (!$this->sameOrigin($from, $to)) {
            unset($options['auth_basic'], $options['auth_bearer']);
            $headers = \is_array($options['headers'] ?? null) ? $options['headers'] : [];
            $options['headers'] = $this->dropHeaders($headers, ['authorization', 'cookie']);
        }
        $dropsBody = 303 === $status || ('POST' === strtoupper($method) && \in_array($status, [301, 302], true));
        if ($dropsBody) {
            unset($options['body'], $options['json']);
            $headers = \is_array($options['headers'] ?? null) ? $options['headers'] : [];
            $options['headers'] = $this->dropHeaders($headers, ['content-length', 'content-type', 'transfer-encoding']);
        }

        return $options;
    }

    private function sameOrigin(string $from, string $to): bool
    {
        $left = EgressDestination::fromUrl($from);
        $right = EgressDestination::fromUrl($to);

        return null !== $left && null !== $right
            && $left->scheme === $right->scheme
            && $left->host === $right->host
            && $left->port === $right->port;
    }

    /**
     * @param array<mixed> $headers
     * @param list<string> $names
     *
     * @return array<mixed>
     */
    private function dropHeaders(array $headers, array $names): array
    {
        $kept = [];
        foreach ($headers as $name => $value) {
            if (\is_int($name)) {
                if (\is_string($value) && 1 === preg_match('/^('.implode('|', $names).')\s*:/i', $value)) {
                    continue;
                }
                $kept[] = $value;
                continue;
            }
            if (\in_array(strtolower((string) $name), $names, true)) {
                continue;
            }
            $kept[$name] = $value;
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function observe(string $url, array $options, string $outcome, int $started, int $status): void
    {
        if ('would_block' === $outcome) {
            $this->wouldBlock($url);
        }
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $this->metrics->record($this->policy->metricDestination($url, $options), $outcome, $ms);
        if (null !== $this->timeline && $this->timeline->isStarted()) {
            $this->timeline->recordEgress($url, 'refused' === $outcome ? 0 : $status, $ms);
        }
    }

    private function wouldBlock(string $url): void
    {
        $this->events?->record([
            'level' => 'warning',
            'channel' => 'egress',
            'event' => 'egress.would_block',
            'message' => (EgressDestination::fromUrl($url)?->label() ?? 'invalid').' '.$this->caller(),
        ]);
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
