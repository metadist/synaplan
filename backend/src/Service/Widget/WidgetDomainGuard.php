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
        private WidgetOriginValidator $origins,
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

        $host = $this->origins->extractHostFromRequest($request);
        if (!$host) {
            throw WidgetRequestRefused::json(['error' => 'Domain not allowed', 'reason' => 'missing_host'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->origins->isHostAllowed($host, $allowedDomains)) {
            $this->logger->warning('Widget request blocked by domain whitelist', [
                'host' => $host,
                'allowed_domains' => $allowedDomains,
            ]);

            throw WidgetRequestRefused::json(['error' => 'Domain not allowed', 'reason' => 'domain_not_whitelisted'], Response::HTTP_FORBIDDEN);
        }
    }
}
