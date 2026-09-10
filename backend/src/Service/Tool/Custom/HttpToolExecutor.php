<?php

declare(strict_types=1);

namespace App\Service\Tool\Custom;

use App\Entity\CustomTool;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Security\SsrfGuard;
use App\Service\Tool\ToolsConfig;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpToolExecutor
{
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
        $this->assertSafe($request['url'], $tool->getOwnerId());

        $response = $this->httpClient->request($request['method'], $request['url'], [
            'headers' => $request['headers'],
            'body' => $request['body'],
            'max_redirects' => 0,
            'timeout' => 15,
            'max_duration' => 20,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            throw new InvalidToolTemplateException('Redirects are not allowed');
        }
        $cap = $this->toolsConfig->maxResponseBytes($tool->getOwnerId());
        $content = $response->getContent(false);
        $truncated = strlen($content) > $cap;
        if ($truncated) {
            $content = substr($content, 0, $cap);
        }
        $decoded = json_decode($content, true);
        $decoded = is_array($decoded) ? $decoded : ['_text' => $content];
        $mapped = $this->mapResponse($tool, $decoded);

        $this->logger->info('HttpToolExecutor: call finished', [
            'tool' => $tool->getName(),
            'owner_id' => $tool->getOwnerId(),
            'actor_id' => $actorId,
            'method' => $request['method'],
            'host' => parse_url($request['url'], \PHP_URL_HOST),
            'status' => $status,
        ]);

        return [
            'status' => $status,
            'summary' => $mapped['summary'],
            'fields' => $mapped['fields'],
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: string|null}
     */
    public function resolve(CustomTool $tool, array $input, bool $includeSecret = false): array
    {
        $spec = $tool->getSpec();
        $credentialHeader = null;
        $headerName = null;
        if (null !== $tool->getCredentialId()) {
            $secret = $this->credentials->reveal($tool->getCredentialId(), $tool->getOwnerId());
            $decoded = json_decode($secret, true);
            if (is_array($decoded)) {
                $headerName = is_string($decoded['name'] ?? null) ? $decoded['name'] : 'Authorization';
                $credentialHeader = is_string($decoded['value'] ?? null) ? $decoded['value'] : $secret;
            } else {
                $headerName = 'Authorization';
                $credentialHeader = $secret;
            }
        }
        $url = $this->templates->render((string) $spec['url'], $input, [], $includeSecret ? $credentialHeader : null);
        $headers = [];
        $rawHeaders = is_array($spec['headers'] ?? null) ? $spec['headers'] : [];
        foreach ($rawHeaders as $name => $value) {
            $headers[(string) $name] = $this->templates->render((string) $value, $input, [], $includeSecret ? $credentialHeader : '***');
        }
        if (null !== $headerName && null !== $credentialHeader) {
            $headers[$headerName] = $includeSecret ? $credentialHeader : '***';
        }
        $body = null;
        if (isset($spec['body'])) {
            $rendered = $this->renderBody($spec['body'], $input, $includeSecret ? $credentialHeader : '***');
            $body = is_string($rendered) ? $rendered : json_encode($rendered, \JSON_THROW_ON_ERROR);
        }

        return [
            'method' => strtoupper((string) ($spec['method'] ?? 'GET')),
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    private function assertSafe(string $url, int $ownerId): void
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if ('http' === $scheme && !$this->toolsConfig->allowPlainHttp($ownerId)) {
            throw new InvalidToolTemplateException('Only https URLs are allowed');
        }
        if ($this->ssrfGuard->isBlockedUrl($url)) {
            throw new InvalidToolTemplateException('This URL is not allowed');
        }
        $host = (string) parse_url($url, \PHP_URL_HOST);
        foreach ($this->resolveIps($host) as $ip) {
            if ($this->ssrfGuard->isBlockedIp($ip)) {
                throw new InvalidToolTemplateException('This URL is not allowed');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        foreach (['A', 'AAAA'] as $type) {
            $records = @dns_get_record($host, 'A' === $type ? \DNS_A : \DNS_AAAA);
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }
        }

        return $ips;
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
