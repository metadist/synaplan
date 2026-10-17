<?php

declare(strict_types=1);

namespace App\Service\Widget;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The domain allowlist of a widget's public endpoints. An empty list lets
 * every site embed the widget; otherwise the requesting host (X-Widget-Host,
 * then Origin, then Referer) must match an entry, with `*.example.com`
 * wildcards and optional ports. The signed-in owner's test and internal
 * requests skip the check.
 */
final readonly class WidgetDomainGuard
{
    public function __construct(
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $config the widget's configuration
     *
     * @throws WidgetRequestRefused when the requesting host may not use the widget
     */
    public function check(array $config, Request $request, ?int $widgetOwnerId = null): void
    {
        $isTestOrInternal = 'true' === $request->headers->get('X-Widget-Test-Mode')
            || 'true' === $request->headers->get('X-Widget-Internal-Mode');

        if ($isTestOrInternal && $widgetOwnerId) {
            $user = $this->security->getUser();
            if ($user && method_exists($user, 'getId') && $user->getId() === $widgetOwnerId) {
                $this->logger->info('Widget domain check bypassed for owner in test/internal mode', [
                    'user_id' => $user->getId(),
                    'widget_owner_id' => $widgetOwnerId,
                ]);

                return;
            }
        }

        $allowedDomains = $config['allowedDomains'] ?? [];
        if (empty($allowedDomains)) {
            return;
        }

        $host = $this->extractHostFromRequest($request);
        if (!$host) {
            throw WidgetRequestRefused::json(['error' => 'Domain not allowed', 'reason' => 'missing_host'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isHostAllowed($host, $allowedDomains)) {
            $this->logger->warning('Widget request blocked by domain whitelist', [
                'host' => $host,
                'allowed_domains' => $allowedDomains,
            ]);

            throw WidgetRequestRefused::json(['error' => 'Domain not allowed', 'reason' => 'domain_not_whitelisted'], Response::HTTP_FORBIDDEN);
        }
    }

    private function extractHostFromRequest(Request $request): ?string
    {
        $headerHost = $request->headers->get('x-widget-host');
        if ($headerHost) {
            $normalized = $this->normalizeHost($headerHost);
            if ($normalized) {
                return $normalized;
            }
        }

        foreach (['origin', 'referer'] as $header) {
            $value = $request->headers->get($header);
            if (!$value) {
                continue;
            }

            $parts = parse_url($value);
            if (false === $parts || !isset($parts['host'])) {
                continue;
            }

            $host = strtolower($parts['host']);
            if (isset($parts['port'])) {
                $host .= ':'.$parts['port'];
            }

            if ('' !== $host) {
                return $host;
            }
        }

        return null;
    }

    private function normalizeHost(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        if ('' === $normalized) {
            return null;
        }

        $normalized = (string) preg_replace('#^https?://#', '', $normalized);
        $normalized = preg_replace('#^//#', '', $normalized);

        if (null === $normalized) {
            return null;
        }

        $parts = preg_split('~[/?#]~', $normalized);
        $normalized = $parts[0] ?? '';

        return '' !== $normalized ? $normalized : null;
    }

    /**
     * @param array<mixed> $allowedDomains
     */
    private function isHostAllowed(string $host, array $allowedDomains): bool
    {
        $host = strtolower($host);
        $hostWithoutPort = $host;
        $hostPort = null;

        if (str_contains($host, ':')) {
            [$hostWithoutPort, $hostPort] = explode(':', $host, 2);
        }

        foreach ($allowedDomains as $domain) {
            if (!is_string($domain) || '' === $domain) {
                continue;
            }

            $domain = strtolower($domain);
            $domainHost = $domain;
            $domainPort = null;

            if (str_contains($domain, ':')) {
                [$domainHost, $domainPort] = explode(':', $domain, 2);
            }

            if (null !== $domainPort && $hostPort !== $domainPort) {
                continue;
            }

            if ($domainHost === $host || $domainHost === $hostWithoutPort) {
                return true;
            }

            if (str_starts_with($domainHost, '*.') && $hostWithoutPort !== $domainHost) {
                $suffix = substr($domainHost, 2);
                if ($suffix && str_ends_with($hostWithoutPort, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
