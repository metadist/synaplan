<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Widget;
use App\Repository\PromptRepository;
use App\Repository\WidgetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Widget Management Service.
 *
 * Handles widget CRUD operations and embed code generation
 */
class WidgetService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WidgetRepository $widgetRepository,
        private PromptRepository $promptRepository,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
    ) {
    }

    public const DEFAULT_WIDGET_PROMPT = 'widget-default';

    /**
     * Create a new widget.
     *
     * @param User        $owner           The widget owner
     * @param string      $name            Widget display name
     * @param string|null $taskPromptTopic Optional task prompt topic (defaults to 'widget-default')
     * @param array       $config          Optional widget configuration
     * @param string|null $websiteUrl      Optional website URL to add to allowed domains
     */
    public function createWidget(User $owner, string $name, ?string $taskPromptTopic = null, array $config = [], ?string $websiteUrl = null): Widget
    {
        // Use default prompt if not specified
        $taskPromptTopic = $taskPromptTopic ?: self::DEFAULT_WIDGET_PROMPT;

        // Validate task prompt exists (check user-specific first, then default)
        $prompt = $this->promptRepository->findByTopic($taskPromptTopic, $owner->getId());
        if (!$prompt) {
            // Try default prompt (BOWNERID = 0)
            $prompt = $this->promptRepository->findByTopic($taskPromptTopic, 0);
            if (!$prompt) {
                throw new \InvalidArgumentException('Task prompt not found: '.$taskPromptTopic);
            }
            $this->logger->info('Using default prompt for widget', [
                'prompt_topic' => $taskPromptTopic,
                'owner_id' => $owner->getId(),
            ]);
        }

        // If websiteUrl is provided, add the domain to allowed domains
        if ($websiteUrl) {
            $domain = $this->extractDomainFromUrl($websiteUrl);
            if ($domain && !in_array($domain, $config['allowedDomains'] ?? [], true)) {
                $config['allowedDomains'] = array_merge($config['allowedDomains'] ?? [], [$domain]);
            }
        }

        $widget = new Widget();
        $widget->setOwner($owner);
        $widget->setName($name);
        $widget->setTaskPromptTopic($taskPromptTopic);
        $sanitizedConfig = $this->sanitizeConfig($config);
        $widget->setConfig($sanitizedConfig);
        $widget->setAllowedDomains($sanitizedConfig['allowedDomains'] ?? []);

        $this->em->persist($widget);
        $this->em->flush();

        $this->logger->info('Widget created', [
            'widget_id' => $widget->getWidgetId(),
            'owner_id' => $owner->getId(),
            'task_prompt' => $taskPromptTopic,
        ]);

        return $widget;
    }

    /**
     * Extract domain from a URL.
     */
    private function extractDomainFromUrl(string $url): ?string
    {
        // Ensure URL has a scheme for parse_url to work correctly
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://'.$url;
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return null;
        }

        $host = strtolower($parsed['host']);

        // Include port if specified
        if (!empty($parsed['port'])) {
            $host .= ':'.$parsed['port'];
        }

        return $host;
    }

    /**
     * Update widget's task prompt topic.
     */
    public function updateWidgetPrompt(Widget $widget, string $taskPromptTopic): void
    {
        $widget->setTaskPromptTopic($taskPromptTopic);
        $widget->touch();
        $this->em->flush();

        $this->logger->info('Widget task prompt updated', [
            'widget_id' => $widget->getWidgetId(),
            'new_topic' => $taskPromptTopic,
        ]);
    }

    /**
     * Update widget configuration.
     */
    public function updateWidget(Widget $widget, array $config): void
    {
        $currentConfig = $widget->getConfig();
        $mergedConfig = array_replace($currentConfig, $config);
        $sanitizedConfig = $this->sanitizeConfig($mergedConfig);

        $widget->setConfig($sanitizedConfig);
        $widget->setAllowedDomains($sanitizedConfig['allowedDomains'] ?? []);
        $widget->touch();
        $this->em->flush();

        $this->logger->debug('Widget configuration updated', [
            'widget_id' => $widget->getWidgetId(),
            'allowed_domains' => $widget->getAllowedDomains(),
        ]);
    }

    /**
     * Update widget name.
     */
    public function updateWidgetName(Widget $widget, string $name): void
    {
        $widget->setName($name);
        $widget->touch();
        $this->em->flush();
    }

    /**
     * Delete widget.
     */
    public function deleteWidget(Widget $widget): void
    {
        $widgetId = $widget->getWidgetId();
        $this->em->remove($widget);
        $this->em->flush();

        $this->logger->info('Widget deleted', [
            'widget_id' => $widgetId,
        ]);
    }

    /**
     * Get widget by widgetId.
     */
    public function getWidgetById(string $widgetId): ?Widget
    {
        return $this->widgetRepository->findOneByWidgetId($widgetId);
    }

    /**
     * List all widgets for a user.
     */
    public function listWidgetsByOwner(User $owner): array
    {
        return $this->widgetRepository->findByOwnerId($owner->getId());
    }

    /**
     * List all widgets for a user by user ID.
     */
    public function getWidgetsByUserId(int $userId): array
    {
        return $this->widgetRepository->findByOwnerId($userId);
    }

    /**
     * Generate embed code for a widget.
     */
    public function generateEmbedCode(Widget $widget, string $baseUrl): string
    {
        $widgetId = $widget->getWidgetId();
        $config = $widget->getConfig();

        // Extract configuration
        $position = $config['position'] ?? 'bottom-right';
        $primaryColor = $config['primaryColor'] ?? '#007bff';
        $iconColor = $config['iconColor'] ?? '#ffffff';
        $buttonIcon = $config['buttonIcon'] ?? 'chat';
        $buttonIconUrl = $config['buttonIconUrl'] ?? '';
        $theme = $config['defaultTheme'] ?? 'light';
        $autoOpen = $config['autoOpen'] ?? false;
        $autoOpenStr = $autoOpen ? 'true' : 'false';
        $autoMessage = $config['autoMessage'] ?? 'Hello! How can I help you today?';
        $messageLimit = (int) ($config['messageLimit'] ?? 50);
        $maxFileSize = (int) ($config['maxFileSize'] ?? 10);
        $allowFileUpload = !empty($config['allowFileUpload']);
        $fileUploadLimit = (int) ($config['fileUploadLimit'] ?? 3);
        $allowFileUploadStr = $allowFileUpload ? 'true' : 'false';

        // Build icon config
        $iconConfig = $buttonIconUrl
            ? "buttonIconUrl: '{$buttonIconUrl}',"
            : "buttonIcon: '{$buttonIcon}',";

        return <<<HTML
<!-- Synaplan Chat Widget (ES Module with Auto Code-Splitting) -->
<script type="module">
  import SynaplanWidget from '{$baseUrl}/widget.js'

  SynaplanWidget.init({
    widgetId: '{$widgetId}',
    position: '{$position}',
    primaryColor: '{$primaryColor}',
    iconColor: '{$iconColor}',
    {$iconConfig}
    defaultTheme: '{$theme}',
    autoOpen: {$autoOpenStr},
    autoMessage: '{$autoMessage}',
    messageLimit: {$messageLimit},
    maxFileSize: {$maxFileSize},
    allowFileUpload: {$allowFileUploadStr},
    fileUploadLimit: {$fileUploadLimit},
    lazy: true  // Load chat on button click (set to false for immediate load)
  })
</script>
HTML;
    }

    /**
     * Generate WordPress shortcode.
     */
    public function generateWordPressShortcode(Widget $widget): string
    {
        return sprintf('[synaplan_widget id="%s"]', $widget->getWidgetId());
    }

    /**
     * Check if widget is active (owner limits not exceeded).
     */
    public function isWidgetActive(Widget $widget): bool
    {
        if (!$widget->isActive()) {
            return false;
        }

        $owner = $widget->getOwner();
        if (!$owner) {
            $owner = $this->em->find(User::class, $widget->getOwnerId());
        }

        if (!$owner instanceof User) {
            $this->logger->warning('Widget owner not found', [
                'widget_id' => $widget->getWidgetId(),
                'owner_id' => $widget->getOwnerId(),
            ]);

            return false;
        }

        // Check owner's usage limits for messages
        $limitCheck = $this->rateLimitService->checkLimit($owner, 'MESSAGES');

        if (!($limitCheck['allowed'] ?? true)) {
            $this->logger->warning('Widget owner rate limit exceeded', [
                'widget_id' => $widget->getWidgetId(),
                'owner_id' => $owner->getId(),
                'remaining' => $limitCheck['remaining'] ?? 0,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Sanitize and validate widget configuration.
     */
    private function sanitizeConfig(array $config): array
    {
        $defaults = [
            'position' => 'bottom-right',
            'primaryColor' => '#007bff',
            'iconColor' => '#ffffff',
            'buttonIcon' => 'chat',
            'buttonIconUrl' => null,
            'defaultTheme' => 'light',
            'autoOpen' => false,
            'autoMessage' => 'Hello! How can I help you today?',
            'messageLimit' => 50,
            'maxFileSize' => 10,
            'allowedDomains' => [],
            'allowFileUpload' => false,
            'fileUploadLimit' => 3,
        ];

        // Apply defaults only for missing keys (not for empty arrays!)
        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $config)) {
                $config[$key] = $defaultValue;
            }
        }

        // Validate position
        $validPositions = ['bottom-left', 'bottom-right', 'top-left', 'top-right'];
        if (!in_array($config['position'], $validPositions)) {
            $config['position'] = 'bottom-right';
        }

        // Validate colors
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $config['primaryColor'])) {
            $config['primaryColor'] = '#007bff';
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $config['iconColor'])) {
            $config['iconColor'] = '#ffffff';
        }

        // Validate button icon
        $validIcons = ['chat', 'headset', 'help', 'robot', 'message', 'support', 'custom'];
        if (!isset($config['buttonIcon']) || !in_array($config['buttonIcon'], $validIcons)) {
            $config['buttonIcon'] = 'chat';
        }

        // Validate custom icon URL (if provided)
        if (isset($config['buttonIconUrl']) && is_string($config['buttonIconUrl']) && '' !== $config['buttonIconUrl']) {
            // Allow data URLs for embedded images or HTTP(S) URLs
            if (!str_starts_with($config['buttonIconUrl'], 'data:image/')
                && !str_starts_with($config['buttonIconUrl'], 'http://')
                && !str_starts_with($config['buttonIconUrl'], 'https://')) {
                $config['buttonIconUrl'] = null;
            }
        } else {
            $config['buttonIconUrl'] = null;
        }

        // Validate theme
        if (!in_array($config['defaultTheme'], ['light', 'dark'])) {
            $config['defaultTheme'] = 'light';
        }

        // Validate limits
        $config['messageLimit'] = max(1, min(100, (int) $config['messageLimit']));
        $config['maxFileSize'] = max(1, min(50, (int) $config['maxFileSize']));

        // Validate boolean flags
        $config['allowFileUpload'] = (bool) ($config['allowFileUpload'] ?? false);

        // Validate file upload limit
        $config['fileUploadLimit'] = max(0, min(20, (int) $config['fileUploadLimit']));

        if (!isset($config['allowedDomains']) || !is_array($config['allowedDomains'])) {
            $config['allowedDomains'] = [];
        }

        try {
            $config['allowedDomains'] = $this->sanitizeAllowedDomains($config['allowedDomains']);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to sanitize allowed domains', [
                'error' => $e->getMessage(),
                'domains' => $config['allowedDomains'],
            ]);
            $config['allowedDomains'] = [];
        }

        return $config;
    }

    /**
     * Normalize and validate the allowed domains list.
     *
     * @param array<string> $domains
     *
     * @return array<string>
     */
    private function sanitizeAllowedDomains(array $domains): array
    {
        $sanitized = [];

        foreach ($domains as $domain) {
            if (!is_string($domain)) {
                continue;
            }

            $normalized = strtolower(trim($domain));
            if ('' === $normalized) {
                continue;
            }

            // Remove protocol if provided - use ~ delimiter to avoid conflict with #
            $normalized = preg_replace('~^https?://~', '', $normalized);
            $normalized = preg_replace('~^//~', '', $normalized);

            if (null === $normalized) {
                continue;
            }

            // Strip any path fragments - use ~ delimiter to avoid conflict with # in character class
            $parts = preg_split('~[/?#]~', $normalized);
            $normalized = $parts[0] ?? '';
            if ('' === $normalized) {
                continue;
            }

            // Validate against allowed pattern
            if (!preg_match('/^(?:\*\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*(?::\d+)?$/', $normalized)) {
                $this->logger->warning('Invalid domain format rejected', [
                    'domain' => $domain,
                    'normalized' => $normalized,
                ]);
                continue;
            }

            if (!in_array($normalized, $sanitized, true)) {
                $sanitized[] = $normalized;
            }
        }

        return array_values($sanitized);
    }
}
