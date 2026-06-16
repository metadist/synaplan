<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Mcp\McpServerFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Model Context Protocol (MCP) server endpoint.
 *
 * Exposes Synaplan capabilities to external MCP hosts (Claude, Cursor, …) over
 * the spec's Streamable HTTP transport. Authentication is handled by the
 * dedicated `mcp` Symfony firewall (API key or OIDC bearer) — this controller
 * only runs once a user is authenticated and drives the official `mcp/sdk`
 * server with a per-request, user-scoped tool catalog.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/transports/streamable-http
 */
class McpController extends AbstractController
{
    /** @var list<string> */
    private array $extraAllowedHosts;

    public function __construct(
        private readonly McpServerFactory $serverFactory,
        private readonly LoggerInterface $logger,
        private readonly string $appUrl,
        private readonly string $oidcDiscoveryUrl,
        string $mcpAllowedHosts,
    ) {
        $this->extraAllowedHosts = array_values(array_filter(array_map(
            'trim',
            explode(',', $mcpAllowedHosts),
        )));
    }

    #[Route('/mcp', name: 'mcp_endpoint', methods: ['POST', 'DELETE', 'OPTIONS'])]
    public function endpoint(Request $request, #[CurrentUser] ?User $user): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return new Response('', Response::HTTP_NO_CONTENT, ['Allow' => 'POST, DELETE, OPTIONS']);
        }

        // The firewall guarantees authentication for POST/DELETE; this is a
        // defensive belt-and-braces check.
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $transport = new StreamableHttpTransport(
            $this->toPsrRequest($request),
            null,
            null,
            $this->logger,
            [
                new ProtocolVersionMiddleware(),
                new DnsRebindingProtectionMiddleware($this->allowedHosts()),
            ],
        );

        $psrResponse = $this->serverFactory->build($user)->run($transport);

        return $this->toSymfonyResponse($psrResponse);
    }

    /**
     * RFC 9728 OAuth 2.0 Protected Resource Metadata.
     *
     * Lets MCP clients discover which authorization server protects this MCP
     * resource. The path-suffix form (`/.well-known/oauth-protected-resource/mcp`)
     * is the canonical one referenced by the `WWW-Authenticate` challenge; the
     * bare form is kept as a convenience fallback.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9728
     */
    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'mcp_protected_resource_metadata', methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource', name: 'mcp_protected_resource_metadata_root', methods: ['GET'])]
    public function protectedResourceMetadata(): JsonResponse
    {
        $metadata = [
            'resource' => rtrim($this->appUrl, '/').'/mcp',
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp:tools'],
        ];

        $issuer = $this->oidcIssuer();
        if (null !== $issuer) {
            $metadata['authorization_servers'] = [$issuer];
        }

        return new JsonResponse($metadata);
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Authentication required', 'code' => 'UNAUTHENTICATED'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => $this->wwwAuthenticate()],
        );
    }

    private function wwwAuthenticate(): string
    {
        return \sprintf(
            'Bearer resource_metadata="%s"',
            rtrim($this->appUrl, '/').'/.well-known/oauth-protected-resource/mcp',
        );
    }

    private function toPsrRequest(Request $request): ServerRequest
    {
        return new ServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $this->normalizeHeaders($request->headers->all()),
            $request->getContent(),
            $request->getProtocolVersion() ?? '1.1',
            $request->server->all(),
        );
    }

    private function toSymfonyResponse(ResponseInterface $psrResponse): Response
    {
        $response = new Response(
            (string) $psrResponse->getBody(),
            $psrResponse->getStatusCode(),
        );

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $response->headers->set($name, $values);
        }

        return $response;
    }

    /**
     * @param array<string, array<int, string|null>> $headers
     *
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $clean = array_values(array_filter(
                $values,
                static fn ($v): bool => null !== $v,
            ));
            if ([] !== $clean) {
                $normalized[$name] = $clean;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $hosts = ['localhost', '127.0.0.1', '[::1]'];

        $appHost = parse_url($this->appUrl, \PHP_URL_HOST);
        if (\is_string($appHost) && '' !== $appHost) {
            $hosts[] = $appHost;
        }

        return array_values(array_unique([...$hosts, ...$this->extraAllowedHosts]));
    }

    private function oidcIssuer(): ?string
    {
        $url = trim($this->oidcDiscoveryUrl);
        if ('' === $url) {
            return null;
        }

        $suffix = '/.well-known/openid-configuration';
        if (str_ends_with($url, $suffix)) {
            $url = substr($url, 0, -\strlen($suffix));
        }

        return rtrim($url, '/');
    }
}
