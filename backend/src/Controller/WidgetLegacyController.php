<?php

namespace App\Controller;

use App\Service\WidgetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

/**
 * Legacy widget.php compatibility layer
 * Returns JavaScript loader for old widget embed code
 */
class WidgetLegacyController extends AbstractController
{
    public function __construct(
        private WidgetService $widgetService,
        private LoggerInterface $logger
    ) {}

    /**
     * Legacy widget.php endpoint - returns JavaScript loader
     * 
     * Old parameters:
     * - uid: User ID (owner of the widget) - used to find widget by owner
     * - widgetid: Widget ID or identifier
     * - mode: Display mode (inline-box, popup, etc.)
     * 
     * This endpoint generates JavaScript that loads the modern widget
     */
    #[Route('/widget.php', name: 'widget_legacy', methods: ['GET'])]
    public function legacyWidget(Request $request): Response
    {
        // Get legacy parameters
        $uid = $request->query->get('uid');
        $widgetId = $request->query->get('widgetid');
        $mode = $request->query->get('mode', 'inline-box');
        
        $this->logger->info('Legacy widget.php accessed', [
            'uid' => $uid,
            'widgetid' => $widgetId,
            'mode' => $mode,
            'referer' => $request->headers->get('referer')
        ]);
        
        // If no widgetId provided, return error as JavaScript
        if (!$widgetId) {
            return new Response(
                "console.error('Synaplan Widget Error: Missing required parameter widgetid');",
                Response::HTTP_OK,
                ['Content-Type' => 'application/javascript']
            );
        }
        
        // Try to find the widget
        // In the new system, widgetId is the unique identifier
        // The uid parameter is legacy and not needed anymore
        $widget = $this->widgetService->getWidgetById($widgetId);
        
        if (!$widget) {
            // If not found by widgetId, try to find by owner + legacy ID combination
            // This is for backwards compatibility
            if ($uid) {
                $this->logger->warning('Widget not found by widgetId, uid provided but not used in new system', [
                    'uid' => $uid,
                    'widgetid' => $widgetId
                ]);
            }
            
            return new Response(
                sprintf(
                    "console.error('Synaplan Widget Error: Widget not found (widgetId: %s)');",
                    addslashes($widgetId)
                ),
                Response::HTTP_OK,
                ['Content-Type' => 'application/javascript']
            );
        }
        
        // Check if widget is active
        if (!$this->widgetService->isWidgetActive($widget)) {
            return new Response(
                "console.error('Synaplan Widget Error: Widget is not active');",
                Response::HTTP_OK,
                ['Content-Type' => 'application/javascript']
            );
        }
        
        // Check domain whitelist
        $config = $widget->getConfig();
        $allowedDomains = $config['allowedDomains'] ?? [];
        
        if (!empty($allowedDomains)) {
            $host = $this->extractHostFromRequest($request);
            
            if (!$host) {
                $this->logger->warning('Widget request blocked: missing host', [
                    'widgetId' => $widget->getWidgetId()
                ]);
                return new Response(
                    "console.error('Synaplan Widget Error: Domain verification failed - missing host');",
                    Response::HTTP_OK,
                    ['Content-Type' => 'application/javascript']
                );
            }
            
            if (!$this->isHostAllowed($host, $allowedDomains)) {
                $this->logger->warning('Widget request blocked by domain whitelist', [
                    'widgetId' => $widget->getWidgetId(),
                    'host' => $host,
                    'allowed_domains' => $allowedDomains
                ]);
                return new Response(
                    sprintf(
                        "console.error('Synaplan Widget Error: Domain not allowed (%s). Allowed domains: %s');",
                        addslashes($host),
                        addslashes(implode(', ', $allowedDomains))
                    ),
                    Response::HTTP_OK,
                    ['Content-Type' => 'application/javascript']
                );
            }
        }
        
        // Get the actual widgetId from the widget entity
        $actualWidgetId = $widget->getWidgetId();
        
        // Build the base URL for the widget
        $apiBaseUrl = $request->getSchemeAndHttpHost();
        
        // Get widget configuration for proper initialization
        $config = $widget->getConfig();
        $position = $config['position'] ?? 'bottom-right';
        $primaryColor = $config['primaryColor'] ?? '#007bff';
        $iconColor = $config['iconColor'] ?? '#ffffff';
        $theme = $config['defaultTheme'] ?? 'light';
        $autoOpen = $config['autoOpen'] ?? false;
        $autoOpenStr = $autoOpen ? 'true' : 'false';
        $autoMessage = $config['autoMessage'] ?? 'Hello! How can I help you today?';
        $messageLimit = (int)($config['messageLimit'] ?? 50);
        $maxFileSize = (int)($config['maxFileSize'] ?? 10);
        $allowFileUpload = !empty($config['allowFileUpload']);
        $fileUploadLimit = (int)($config['fileUploadLimit'] ?? 3);
        $allowFileUploadStr = $allowFileUpload ? 'true' : 'false';
        
        // Escape strings for JavaScript
        $autoMessage = addslashes($autoMessage);
        
        // Generate JavaScript loader that initializes the modern widget
        // This mimics the old widget.php behavior while using the new widget.js
        $javascript = <<<JS
(function() {
    'use strict';
    
    // Legacy widget.php compatibility layer
    console.log('Synaplan Widget: Loading via legacy widget.php endpoint (uid parameter is deprecated)');
    
    // Load the modern widget script
    var script = document.createElement('script');
    script.src = '{$apiBaseUrl}/widget.js';
    script.async = true;
    script.onload = function() {
        // Initialize widget when script is loaded
        if (window.SynaplanWidget) {
            window.SynaplanWidget.init({
                widgetId: '{$actualWidgetId}',
                position: '{$position}',
                primaryColor: '{$primaryColor}',
                iconColor: '{$iconColor}',
                defaultTheme: '{$theme}',
                autoOpen: {$autoOpenStr},
                autoMessage: '{$autoMessage}',
                messageLimit: {$messageLimit},
                maxFileSize: {$maxFileSize},
                allowFileUpload: {$allowFileUploadStr},
                fileUploadLimit: {$fileUploadLimit},
                apiUrl: '{$apiBaseUrl}',
                mode: '{$mode}'
            });
        } else {
            console.error('Synaplan Widget: Failed to initialize - SynaplanWidget not found');
        }
    };
    script.onerror = function() {
        console.error('Synaplan Widget: Failed to load widget script from ' + script.src);
    };
    
    // Add script to page
    document.head.appendChild(script);
})();
JS;
        
        return new Response(
            $javascript,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/javascript',
                'Cache-Control' => 'public, max-age=3600', // Cache for 1 hour
                'X-Widget-Id' => $actualWidgetId
            ]
        );
    }
    
    /**
     * Extract host from request (checks x-widget-host header, origin, and referer)
     */
    private function extractHostFromRequest(Request $request): ?string
    {
        // Check custom header first (set by widget.js)
        $headerHost = $request->headers->get('x-widget-host');
        if ($headerHost) {
            $normalized = $this->normalizeHost($headerHost);
            if ($normalized) {
                return $normalized;
            }
        }

        // Check Origin and Referer headers
        foreach (['origin', 'referer'] as $header) {
            $value = $request->headers->get($header);
            if (!$value) {
                continue;
            }

            $parts = parse_url($value);
            if ($parts === false || !isset($parts['host'])) {
                continue;
            }

            $host = strtolower($parts['host']);
            if (isset($parts['port'])) {
                $host .= ':' . $parts['port'];
            }

            if ($host !== '') {
                return $host;
            }
        }

        return null;
    }

    /**
     * Normalize host (remove protocol, trailing slashes, etc.)
     */
    private function normalizeHost(?string $host): ?string
    {
        if (!$host) {
            return null;
        }

        $host = trim($host);
        $host = preg_replace('#^https?://#i', '', $host);
        $host = rtrim($host, '/');
        $host = strtolower($host);

        return $host !== '' ? $host : null;
    }

    /**
     * Check if host is allowed (supports wildcards)
     */
    private function isHostAllowed(string $host, array $allowedDomains): bool
    {
        $host = strtolower($host);

        foreach ($allowedDomains as $allowedDomain) {
            $allowedDomain = strtolower(trim($allowedDomain));

            // Exact match
            if ($host === $allowedDomain) {
                return true;
            }

            // Wildcard match (*.example.com)
            if (str_starts_with($allowedDomain, '*.')) {
                $pattern = substr($allowedDomain, 2);
                if (str_ends_with($host, '.' . $pattern) || $host === $pattern) {
                    return true;
                }
            }

            // Subdomain match (example.com allows www.example.com)
            if (str_ends_with($host, '.' . $allowedDomain)) {
                return true;
            }
        }

        return false;
    }
}

