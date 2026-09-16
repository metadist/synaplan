<?php

declare(strict_types=1);

namespace App\Service\Tool\Custom;

use App\Service\Security\SsrfGuard;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OpenApiImporter
{
    public const MAX_OPERATIONS = 200;
    public const MAX_SPEC_BYTES = 2097152;

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
    ) {
    }

    /**
     * @return array{operations: list<array<string, mixed>>, dropped: int, notices: list<string>}
     */
    public function previewFromUrl(string $url): array
    {
        if ($this->ssrfGuard->isBlockedUrl($url)) {
            throw new InvalidToolTemplateException('This URL is not allowed');
        }
        try {
            $response = $this->httpClient->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => 15,
                'max_duration' => 20,
            ]);
            if (200 !== $response->getStatusCode()) {
                $response->cancel();
                throw new InvalidToolTemplateException(sprintf('The description could not be fetched (HTTP %d)', $response->getStatusCode()));
            }
            $content = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isLast()) {
                    break;
                }
                $content .= $chunk->getContent();
                if (strlen($content) > self::MAX_SPEC_BYTES) {
                    $response->cancel();
                    throw new InvalidToolTemplateException('The description is too large');
                }
            }
        } catch (HttpClientExceptionInterface $e) {
            throw new InvalidToolTemplateException('The description could not be fetched: '.$e->getMessage(), 0, $e);
        }

        return $this->previewFromDocument($content, $url);
    }

    /**
     * @return array{operations: list<array<string, mixed>>, dropped: int, notices: list<string>}
     */
    public function previewFromDocument(string $document, string $sourceRef = 'upload'): array
    {
        $spec = $this->parse($document);
        $ops = [];
        $notices = [];
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        foreach ($paths as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }
            foreach ($methods as $method => $operation) {
                if (!is_array($operation)) {
                    continue;
                }
                $http = strtoupper((string) $method);
                if (!in_array($http, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                $operationId = is_string($operation['operationId'] ?? null) ? $operation['operationId'] : $http.'_'.trim((string) $path, '/');
                $ops[] = [
                    'operationId' => $operationId,
                    'summary' => (string) ($operation['summary'] ?? $operationId),
                    'method' => $http,
                    'path' => (string) $path,
                    'sideEffect' => $this->guessClass($http),
                    'inputSchema' => $this->flattenParameters($operation),
                    'sourceRef' => $sourceRef.'#'.$operationId,
                ];
                if (count($ops) >= self::MAX_OPERATIONS) {
                    $notices[] = 'Only the first 200 operations are listed';
                    break 2;
                }
            }
        }

        return ['operations' => $ops, 'dropped' => 0, 'notices' => $notices];
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $document): array
    {
        $json = json_decode($document, true);
        if (is_array($json)) {
            $this->assertOpenApi($json);

            return $json;
        }
        try {
            $yaml = Yaml::parse($document);
        } catch (ParseException $e) {
            throw new InvalidToolTemplateException('The description must be OpenAPI 3 JSON or YAML: '.$e->getMessage(), 0, $e);
        }
        if (!is_array($yaml)) {
            throw new InvalidToolTemplateException('The description must be OpenAPI 3 JSON or YAML');
        }
        $this->assertOpenApi($yaml);

        return $yaml;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function assertOpenApi(array $spec): void
    {
        $version = (string) ($spec['openapi'] ?? '');
        if (!str_starts_with($version, '3.')) {
            throw new InvalidToolTemplateException('Only OpenAPI 3.0 and 3.1 are supported');
        }
        $encoded = json_encode($spec);
        if (is_string($encoded) && str_contains($encoded, '"$ref":"http')) {
            throw new InvalidToolTemplateException('Remote $ref is not allowed');
        }
    }

    private function guessClass(string $method): string
    {
        return match ($method) {
            'GET', 'HEAD' => 'read',
            'DELETE' => 'destructive',
            default => 'write',
        };
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private function flattenParameters(array $operation): array
    {
        $properties = [];
        $required = [];
        $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $name = (string) ($parameter['name'] ?? '');
            if ('' === $name) {
                continue;
            }
            $schema = is_array($parameter['schema'] ?? null) ? $parameter['schema'] : ['type' => 'string'];
            $properties[$name] = $schema;
            if (true === ($parameter['required'] ?? false)) {
                $required[] = $name;
            }
        }
        $body = $operation['requestBody']['content']['application/json']['schema']['properties'] ?? null;
        if (is_array($body)) {
            foreach ($body as $name => $schema) {
                if (is_string($name) && is_array($schema)) {
                    $properties[$name] = $schema;
                }
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return $schema;
    }
}
