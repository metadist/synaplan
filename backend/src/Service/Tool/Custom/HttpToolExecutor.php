<?php

declare(strict_types=1);

namespace App\Service\Tool\Custom;

use App\Entity\CustomTool;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Security\SsrfGuard;
use App\Service\Tool\ToolsConfig;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Executes one custom HTTP tool call.
 *
 * Safety properties: the SSRF guard runs on the rendered URL, the resolved
 * address is pinned for the actual request (no DNS rebinding between check and
 * call), redirects are refused, the body is read as a stream and cut at the
 * operator's byte cap, and every transport failure surfaces as
 * {@see InvalidToolTemplateException} so callers never see raw client errors.
 */
final readonly class HttpToolExecutor
{
    private const TIMEOUT_SECONDS = 15;
    private const MAX_DURATION_SECONDS = 20;
    private const REDACTED = '***';

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
        private TemplateRenderer $templates,
        private ToolsConfig $toolsConfig,
        private CredentialVaultInterface $credentials,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{status: int, summary: string, fields: array<string, mixed>, truncated: bool}
     */
    public function execute(CustomTool $tool, array $input, int $actorId): array
    {
        $request = $this->resolve($tool, $input, includeSecret: true);
        $pinnedIp = $this->assertSafe($request['url'], $tool->getOwnerId());
        $host = (string) parse_url($request['url'], \PHP_URL_HOST);

        $options = [
            'headers' => $request['headers'],
            'body' => $request['body'],
            'max_redirects' => 0,
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::MAX_DURATION_SECONDS,
        ];
        if (null !== $pinnedIp) {
            $options['resolve'] = [$host => $pinnedIp];
        }

        try {
            $response = $this->httpClient->request($request['method'], $request['url'], $options);
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                $response->cancel();
                throw new InvalidToolTemplateException('Redirects are not allowed');
            }
            [$content, $truncated] = $this->readCapped($response, $this->toolsConfig->maxResponseBytes($tool->getOwnerId()));
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->info('HttpToolExecutor: call failed', [
                'tool' => $tool->getName(),
                'owner_id' => $tool->getOwnerId(),
                'actor_id' => $actorId,
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
            throw new InvalidToolTemplateException('The service did not answer: '.$e->getMessage(), 0, $e);
        }

        $decoded = json_decode($content, true);
        $decoded = is_array($decoded) ? $decoded : ['_text' => $content];
        $mapped = $this->mapResponse($tool, $decoded);

        $this->logger->info('HttpToolExecutor: call finished', [
            'tool' => $tool->getName(),
            'owner_id' => $tool->getOwnerId(),
            'actor_id' => $actorId,
            'method' => $request['method'],
            'host' => $host,
            'status' => $status,
            'truncated' => $truncated,
        ]);

        return [
            'status' => $status,
            'summary' => $mapped['summary'],
            'fields' => $mapped['fields'],
            'truncated' => $truncated,
        ];
    }

    /**
     * Render the request without sending it. With `includeSecret` false the
     * credential is replaced by a marker everywhere it would appear (try-it).
     *
     * @param array<string, mixed> $input
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: string|null}
     */
    public function resolve(CustomTool $tool, array $input, bool $includeSecret = false): array
    {
        $spec = $tool->getSpec();
        [$headerName, $credentialHeader] = $this->credential($tool);
        $secretForTemplates = $includeSecret ? $credentialHeader : (null === $credentialHeader ? null : self::REDACTED);

        $url = $this->templates->render((string) $spec['url'], $this->encodeForUrl($input), [], $secretForTemplates);
        $query = [];
        $rawQuery = is_array($spec['query'] ?? null) ? $spec['query'] : [];
        foreach ($rawQuery as $name => $value) {
            $query[(string) $name] = $this->templates->render((string) $value, $input, [], $secretForTemplates);
        }
        if ([] !== $query) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        }

        $headers = [];
        $rawHeaders = is_array($spec['headers'] ?? null) ? $spec['headers'] : [];
        foreach ($rawHeaders as $name => $value) {
            $headers[(string) $name] = $this->templates->render((string) $value, $input, [], $secretForTemplates);
        }
        if (null !== $headerName && null !== $credentialHeader) {
            $headers[$headerName] = $includeSecret ? $credentialHeader : self::REDACTED;
        }

        $body = null;
        if (isset($spec['body'])) {
            $rendered = $this->renderBody($spec['body'], $input, $secretForTemplates);
            $body = is_string($rendered) ? $rendered : json_encode($rendered, \JSON_THROW_ON_ERROR);
        }

        return [
            'method' => strtoupper((string) ($spec['method'] ?? 'GET')),
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null} header name and value
     */
    private function credential(CustomTool $tool): array
    {
        if (null === $tool->getCredentialId()) {
            return [null, null];
        }
        $secret = $this->credentials->reveal($tool->getCredentialId(), $tool->getOwnerId());
        $decoded = json_decode($secret, true);
        if (!is_array($decoded)) {
            return ['Authorization', $secret];
        }
        $name = is_string($decoded['name'] ?? null) && '' !== $decoded['name'] ? $decoded['name'] : 'Authorization';
        $value = is_string($decoded['value'] ?? null) ? $decoded['value'] : $secret;

        return [$name, $value];
    }

    /**
     * Returns the address to pin the request to, or null for a literal IP host.
     */
    private function assertSafe(string $url, int $ownerId): ?string
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if ('http' === $scheme && !$this->toolsConfig->allowPlainHttp($ownerId)) {
            throw new InvalidToolTemplateException('Only https URLs are allowed');
        }
        if ($this->ssrfGuard->isBlockedUrl($url)) {
            throw new InvalidToolTemplateException('This URL is not allowed');
        }
        $host = trim((string) parse_url($url, \PHP_URL_HOST), '[]');
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return null;
        }
        $ips = $this->resolveIps($host);
        if ([] === $ips) {
            throw new InvalidToolTemplateException('This address could not be resolved');
        }
        foreach ($ips as $ip) {
            if ($this->ssrfGuard->isBlockedIp($ip)) {
                throw new InvalidToolTemplateException('This URL is not allowed');
            }
        }

        return $ips[0];
    }

    /**
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $aaaa = @dns_get_record($host, \DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (is_string($record['ipv6'] ?? null) && '' !== $record['ipv6']) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return array{0: string, 1: bool} body and whether it was cut at the cap
     */
    private function readCapped(ResponseInterface $response, int $cap): array
    {
        $content = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isLast()) {
                break;
            }
            $content .= $chunk->getContent();
            if (strlen($content) > $cap) {
                $response->cancel();

                return [substr($content, 0, $cap), true];
            }
        }

        return [$content, false];
    }

    /**
     * Path segments must carry percent-encoded input; query values are encoded
     * separately via http_build_query.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function encodeForUrl(array $input): array
    {
        $encoded = [];
        foreach ($input as $key => $value) {
            $encoded[$key] = is_scalar($value) ? rawurlencode((string) $value) : $value;
        }

        return $encoded;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array{summary: string, fields: array<string, mixed>}
     */
    private function mapResponse(CustomTool $tool, array $response): array
    {
        $mapping = is_array($tool->getSpec()['response'] ?? null) ? $tool->getSpec()['response'] : [];
        $summaryTemplate = is_string($mapping['summary'] ?? null) ? $mapping['summary'] : '';
        $fields = [];
        $wanted = is_array($mapping['fields'] ?? null) ? $mapping['fields'] : [];
        foreach ($wanted as $field) {
            if (is_string($field) && array_key_exists($field, $response)) {
                $fields[$field] = $response[$field];
            }
        }
        $summary = '' !== $summaryTemplate
            ? $this->templates->render($summaryTemplate, [], $this->flatten($response))
            : 'Request finished';

        return ['summary' => $summary, 'fields' => $fields];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function flatten(array $response): array
    {
        $out = [];
        foreach ($response as $key => $value) {
            if (is_scalar($value) || null === $value) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function renderBody(mixed $body, array $input, ?string $credentialHeader): mixed
    {
        if (is_string($body)) {
            return $this->templates->render($body, $input, [], $credentialHeader);
        }
        if (is_array($body)) {
            $out = [];
            foreach ($body as $key => $value) {
                $out[$key] = $this->renderBody($value, $input, $credentialHeader);
            }

            return $out;
        }

        return $body;
    }
}
