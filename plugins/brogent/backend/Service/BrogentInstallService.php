<?php

declare(strict_types=1);

namespace Plugin\Brogent\Service;

use App\Service\PluginDataService;
use Psr\Log\LoggerInterface;

/**
 * Installation service for BroGent plugin.
 *
 * Seeds default tasks when plugin is installed for a user.
 */
final class BrogentInstallService
{
    private const PLUGIN_NAME = 'brogent';

    public function __construct(
        private PluginDataService $pluginData,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Seed default tasks for a user.
     *
     * Called automatically when plugin is installed via:
     * php bin/console app:plugin:install <userId> brogent
     */
    public function seedDefaultTasks(int $userId): void
    {
        $this->logger->info('Seeding default BroGent tasks', ['user_id' => $userId]);

        $tasks = $this->getDefaultTasks();

        foreach ($tasks as $taskId => $taskData) {
            // Only seed if task doesn't exist
            if (!$this->pluginData->exists($userId, self::PLUGIN_NAME, 'task', $taskId)) {
                $this->pluginData->set($userId, self::PLUGIN_NAME, 'task', $taskId, $taskData);
                $this->logger->info('Seeded task', ['task_id' => $taskId, 'name' => $taskData['name']]);
            }
        }

        $this->logger->info('BroGent task seeding completed', [
            'user_id' => $userId,
            'tasks_count' => count($tasks),
        ]);
    }

    /**
     * Get default task definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getDefaultTasks(): array
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return [
            // Simple test task - navigate and screenshot
            'task_demo_screenshot' => [
                'taskId' => 'task_demo_screenshot',
                'name' => 'Demo: Screenshot Page',
                'description' => 'Navigate to a URL and take a screenshot',
                'enabled' => true,
                'version' => 1,
                'dslVersion' => 1,
                'site' => [
                    'key' => 'any',
                    'domainPatterns' => ['*'],
                ],
                'inputsSchema' => [
                    'type' => 'object',
                    'required' => ['url'],
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'URL to navigate to',
                            'default' => 'https://example.com',
                        ],
                    ],
                ],
                'steps' => [
                    [
                        'id' => 's1',
                        'type' => 'navigate',
                        'url' => '${inputs.url}',
                    ],
                    [
                        'id' => 's2',
                        'type' => 'sleep',
                        'ms' => 2000,
                    ],
                    [
                        'id' => 's3',
                        'type' => 'screenshot',
                        'label' => 'page-loaded',
                    ],
                ],
                'requiredScopes' => [],
                'risk' => 'low',
                'createdAt' => $now,
                'updatedAt' => $now,
            ],

            // Test task with user input
            'task_demo_search' => [
                'taskId' => 'task_demo_search',
                'name' => 'Demo: Google Search',
                'description' => 'Search Google for a query',
                'enabled' => true,
                'version' => 1,
                'dslVersion' => 1,
                'site' => [
                    'key' => 'google',
                    'domainPatterns' => ['https://www.google.com/*', 'https://google.com/*'],
                ],
                'inputsSchema' => [
                    'type' => 'object',
                    'required' => ['query'],
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query',
                        ],
                    ],
                ],
                'steps' => [
                    [
                        'id' => 's1',
                        'type' => 'navigate',
                        'url' => 'https://www.google.com',
                    ],
                    [
                        'id' => 's2',
                        'type' => 'wait_for_visible',
                        'selector' => [
                            'kind' => 'css',
                            'value' => 'textarea[name="q"], input[name="q"]',
                        ],
                        'timeoutMs' => 10000,
                    ],
                    [
                        'id' => 's3',
                        'type' => 'type',
                        'selector' => [
                            'kind' => 'css',
                            'value' => 'textarea[name="q"], input[name="q"]',
                        ],
                        'text' => '${inputs.query}',
                        'clear' => true,
                    ],
                    [
                        'id' => 's4',
                        'type' => 'press',
                        'key' => 'Enter',
                    ],
                    [
                        'id' => 's5',
                        'type' => 'sleep',
                        'ms' => 3000,
                    ],
                    [
                        'id' => 's6',
                        'type' => 'screenshot',
                        'label' => 'search-results',
                    ],
                ],
                'requiredScopes' => [],
                'risk' => 'low',
                'createdAt' => $now,
                'updatedAt' => $now,
            ],

            // Task with approval required
            'task_demo_approval' => [
                'taskId' => 'task_demo_approval',
                'name' => 'Demo: With Approval',
                'description' => 'Demo task that requires user approval',
                'enabled' => true,
                'version' => 1,
                'dslVersion' => 1,
                'site' => [
                    'key' => 'any',
                    'domainPatterns' => ['*'],
                ],
                'inputsSchema' => [
                    'type' => 'object',
                    'required' => ['url', 'message'],
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'URL to navigate to',
                            'default' => 'https://example.com',
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'Message to display in approval',
                        ],
                    ],
                ],
                'steps' => [
                    [
                        'id' => 's1',
                        'type' => 'navigate',
                        'url' => '${inputs.url}',
                    ],
                    [
                        'id' => 's2',
                        'type' => 'screenshot',
                        'label' => 'before-approval',
                    ],
                    [
                        'id' => 's3',
                        'type' => 'require_approval',
                        'kind' => 'demo_action',
                        'summaryTemplate' => 'Approve demo action: ${inputs.message}',
                        'risk' => 'medium',
                    ],
                    [
                        'id' => 's4',
                        'type' => 'screenshot',
                        'label' => 'after-approval',
                    ],
                ],
                'requiredScopes' => [],
                'risk' => 'medium',
                'createdAt' => $now,
                'updatedAt' => $now,
            ],

            // Mock WhatsApp send message (for testing with mock site)
            'task_whatsapp_send' => [
                'taskId' => 'task_whatsapp_send',
                'name' => 'WhatsApp: Send Message',
                'description' => 'Send a WhatsApp message (requires mock site for testing)',
                'enabled' => true,
                'version' => 1,
                'dslVersion' => 1,
                'site' => [
                    'key' => 'whatsapp_web',
                    'domainPatterns' => ['https://web.whatsapp.com/*', 'http://localhost:*/mock/whatsapp*'],
                ],
                'inputsSchema' => [
                    'type' => 'object',
                    'required' => ['to', 'message'],
                    'properties' => [
                        'to' => [
                            'type' => 'string',
                            'description' => 'Phone number or contact name',
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'Message to send',
                        ],
                    ],
                ],
                'steps' => [
                    [
                        'id' => 's1',
                        'type' => 'navigate',
                        'url' => 'https://web.whatsapp.com/',
                    ],
                    [
                        'id' => 's2',
                        'type' => 'wait_for_visible',
                        'selector' => [
                            'kind' => 'css',
                            'value' => '[data-testid="chat-list"], #mock-chat-list',
                        ],
                        'timeoutMs' => 30000,
                    ],
                    [
                        'id' => 's3',
                        'type' => 'click',
                        'selector' => [
                            'kind' => 'css',
                            'value' => '[data-testid="new-chat"], #mock-new-chat',
                        ],
                    ],
                    [
                        'id' => 's4',
                        'type' => 'type',
                        'selector' => [
                            'kind' => 'css',
                            'value' => '[data-testid="search"], #mock-search',
                        ],
                        'text' => '${inputs.to}',
                        'clear' => true,
                    ],
                    [
                        'id' => 's5',
                        'type' => 'sleep',
                        'ms' => 1000,
                    ],
                    [
                        'id' => 's6',
                        'type' => 'click',
                        'selector' => [
                            'kind' => 'css',
                            'value' => '[data-testid="contact-result-0"], .mock-contact-result:first-child',
                        ],
                    ],
                    [
                        'id' => 's7',
                        'type' => 'require_approval',
                        'kind' => 'send_message',
                        'summaryTemplate' => 'Send WhatsApp message to ${inputs.to}: "${inputs.message}"',
                        'risk' => 'high',
                    ],
                    [
                        'id' => 's8',
                        'type' => 'type',
                        'selector' => [
                            'kind' => 'css',
                            'value' => '[data-testid="composer"], #mock-composer',
                        ],
                        'text' => '${inputs.message}',
                    ],
                    [
                        'id' => 's9',
                        'type' => 'press',
                        'key' => 'Enter',
                    ],
                    [
                        'id' => 's10',
                        'type' => 'screenshot',
                        'label' => 'after-send',
                    ],
                ],
                'requiredScopes' => ['whatsapp:send'],
                'risk' => 'high',
                'createdAt' => $now,
                'updatedAt' => $now,
            ],
        ];
    }
}
