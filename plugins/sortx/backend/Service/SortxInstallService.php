<?php

declare(strict_types=1);

namespace Plugin\SortX\Service;

use App\Service\PluginDataService;

/**
 * Seeds default categories and fields when plugin is installed for a user.
 *
 * Uses PluginDataService for non-invasive data storage.
 */
final readonly class SortxInstallService
{
    private const PLUGIN_NAME = 'sortx';
    private const DATA_TYPE_CATEGORY = 'category';

    public function __construct(
        private PluginDataService $pluginData,
    ) {
    }

    /**
     * Seed default categories and fields for a user.
     * Safe to call multiple times (checks for existing categories).
     */
    public function seedDefaultCategories(int $userId): void
    {
        // Skip if user already has categories
        if ($this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_CATEGORY) > 0) {
            return;
        }

        $defaults = $this->getDefaultCategoryDefinitions();

        foreach ($defaults as $sortOrder => $catDef) {
            $this->pluginData->set(
                $userId,
                self::PLUGIN_NAME,
                self::DATA_TYPE_CATEGORY,
                $catDef['key'],
                [
                    'name' => $catDef['name'],
                    'description' => $catDef['description'],
                    'enabled' => true,
                    'sort_order' => $sortOrder,
                    'fields' => $catDef['fields'] ?? [],
                ]
            );
        }
    }

    /**
     * Check if user has any categories configured.
     */
    public function userHasCategories(int $userId): bool
    {
        return $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_CATEGORY) > 0;
    }

    /**
     * Universal fields for ALL categories.
     * These are always extracted regardless of document type.
     */
    private function getUniversalFields(): array
    {
        return [
            ['key' => 'document_date', 'name' => 'Document Date', 'type' => 'date', 'description' => 'Primary date of the document'],
            ['key' => 'sender', 'name' => 'Sender', 'type' => 'text', 'description' => 'Who created/sent the document'],
            ['key' => 'recipient', 'name' => 'Recipient', 'type' => 'text', 'description' => 'Who receives the document'],
        ];
    }

    /**
     * @return array<int, array{key: string, name: string, description: string, fields?: array<int, array{key: string, name: string, type: string, description?: string, enum_values?: array, required?: bool}>}>
     */
    private function getDefaultCategoryDefinitions(): array
    {
        $universal = $this->getUniversalFields();

        return [
            [
                'key' => 'contract',
                'name' => 'Contract',
                'description' => 'Legal agreements, contracts, leases, NDAs, terms of service',
                'fields' => array_merge($universal, [
                    ['key' => 'effective_date', 'name' => 'Effective Date', 'type' => 'date', 'description' => 'When the contract becomes effective'],
                    ['key' => 'expiration_date', 'name' => 'Expiration Date', 'type' => 'date', 'description' => 'When the contract expires'],
                    ['key' => 'value', 'name' => 'Contract Value', 'type' => 'number', 'description' => 'Total monetary value'],
                    ['key' => 'currency', 'name' => 'Currency', 'type' => 'enum', 'enum_values' => ['EUR', 'USD', 'GBP', 'CHF', 'Other']],
                ]),
            ],
            [
                'key' => 'invoice',
                'name' => 'Invoice',
                'description' => 'Bills, invoices, receipts, payment documents',
                'fields' => array_merge($universal, [
                    ['key' => 'amount', 'name' => 'Amount', 'type' => 'number', 'description' => 'Total invoice amount'],
                    ['key' => 'currency', 'name' => 'Currency', 'type' => 'enum', 'enum_values' => ['EUR', 'USD', 'GBP', 'CHF', 'Other']],
                    ['key' => 'due_date', 'name' => 'Due Date', 'type' => 'date', 'description' => 'Payment due date'],
                    ['key' => 'invoice_number', 'name' => 'Invoice Number', 'type' => 'text', 'description' => 'Unique invoice identifier'],
                ]),
            ],
            [
                'key' => 'letter',
                'name' => 'Letter',
                'description' => 'Correspondence, formal letters, notifications, official communications',
                'fields' => array_merge($universal, [
                    ['key' => 'subject', 'name' => 'Subject', 'type' => 'text', 'description' => 'Main topic or subject line'],
                ]),
            ],
            [
                'key' => 'research',
                'name' => 'Research',
                'description' => 'Academic papers, studies, whitepapers, theses, scientific documents',
                'fields' => array_merge($universal, [
                    ['key' => 'title', 'name' => 'Title', 'type' => 'text', 'description' => 'Paper or document title'],
                    ['key' => 'authors', 'name' => 'Authors', 'type' => 'text', 'description' => 'Author names'],
                    ['key' => 'topic', 'name' => 'Topic/Field', 'type' => 'text', 'description' => 'Research area or field'],
                ]),
            ],
            [
                'key' => 'human_resources',
                'name' => 'Human Resources',
                'description' => 'CVs, resumes, job applications, personnel files, employment documents',
                'fields' => array_merge($universal, [
                    ['key' => 'person_name', 'name' => 'Person Name', 'type' => 'text'],
                    ['key' => 'document_type', 'name' => 'Document Type', 'type' => 'enum', 'enum_values' => ['CV', 'Resume', 'Application', 'Contract', 'Performance Review', 'Other']],
                    ['key' => 'position', 'name' => 'Position/Role', 'type' => 'text'],
                ]),
            ],
            [
                'key' => 'sales',
                'name' => 'Sales',
                'description' => 'Quotes, quotations, offers, orders, purchase documents, proposals',
                'fields' => array_merge($universal, [
                    ['key' => 'amount', 'name' => 'Amount', 'type' => 'number'],
                    ['key' => 'currency', 'name' => 'Currency', 'type' => 'enum', 'enum_values' => ['EUR', 'USD', 'GBP', 'CHF', 'Other']],
                    ['key' => 'status', 'name' => 'Status', 'type' => 'enum', 'enum_values' => ['Draft', 'Sent', 'Accepted', 'Rejected', 'Expired']],
                ]),
            ],
            [
                'key' => 'unknown',
                'name' => 'Unknown',
                'description' => 'Unclassified documents - GDPR assessment required',
                'fields' => array_merge($universal, [
                    ['key' => 'topic', 'name' => 'Topic', 'type' => 'text', 'description' => 'Brief topic (max 4 words)'],
                    ['key' => 'gdpr_relevant', 'name' => 'GDPR Relevant', 'type' => 'boolean', 'description' => 'Contains personal data?'],
                    ['key' => 'gdpr_confidence', 'name' => 'GDPR Confidence', 'type' => 'number', 'description' => 'Confidence in GDPR assessment (0-1)'],
                    ['key' => 'gdpr_indicators', 'name' => 'GDPR Indicators', 'type' => 'text', 'description' => 'What triggered GDPR flag (addresses, birthdates, names)'],
                ]),
            ],
        ];
    }
}
