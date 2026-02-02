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
     * @return array<int, array{key: string, name: string, description: string, fields?: array<int, array{key: string, name: string, type: string, description?: string, enum_values?: array, required?: bool}>}>
     */
    private function getDefaultCategoryDefinitions(): array
    {
        return [
            [
                'key' => 'contract',
                'name' => 'Contract',
                'description' => 'Legal agreements, contracts, leases, NDAs, terms of service',
                'fields' => [
                    ['key' => 'buyer', 'name' => 'Buyer/Client', 'type' => 'text', 'description' => 'The party purchasing or receiving services'],
                    ['key' => 'seller', 'name' => 'Seller/Provider', 'type' => 'text', 'description' => 'The party providing goods or services'],
                    ['key' => 'effective_date', 'name' => 'Effective Date', 'type' => 'date', 'description' => 'When the contract becomes effective'],
                    ['key' => 'expiration_date', 'name' => 'Expiration Date', 'type' => 'date', 'description' => 'When the contract expires'],
                    ['key' => 'value', 'name' => 'Contract Value', 'type' => 'number', 'description' => 'Total monetary value'],
                    ['key' => 'currency', 'name' => 'Currency', 'type' => 'enum', 'enum_values' => ['EUR', 'USD', 'GBP', 'CHF', 'Other']],
                ],
            ],
            [
                'key' => 'invoice',
                'name' => 'Invoice',
                'description' => 'Bills, invoices, receipts, payment documents',
                'fields' => [
                    ['key' => 'sender', 'name' => 'Sender', 'type' => 'text', 'description' => 'Company or person who issued the invoice'],
                    ['key' => 'recipient', 'name' => 'Recipient', 'type' => 'text', 'description' => 'Company or person receiving the invoice'],
                    ['key' => 'amount', 'name' => 'Amount', 'type' => 'number', 'description' => 'Total invoice amount'],
                    ['key' => 'currency', 'name' => 'Currency', 'type' => 'enum', 'enum_values' => ['EUR', 'USD', 'GBP', 'CHF', 'Other']],
                    ['key' => 'invoice_date', 'name' => 'Invoice Date', 'type' => 'date', 'description' => 'Date the invoice was issued'],
                    ['key' => 'due_date', 'name' => 'Due Date', 'type' => 'date', 'description' => 'Payment due date'],
                    ['key' => 'invoice_number', 'name' => 'Invoice Number', 'type' => 'text', 'description' => 'Unique invoice identifier'],
                ],
            ],
            [
                'key' => 'letter',
                'name' => 'Letter',
                'description' => 'Correspondence, formal letters, notifications, official communications',
                'fields' => [
                    ['key' => 'sender', 'name' => 'Sender', 'type' => 'text', 'description' => 'Who sent the letter'],
                    ['key' => 'recipient', 'name' => 'Recipient', 'type' => 'text', 'description' => 'Who received the letter'],
                    ['key' => 'date', 'name' => 'Date', 'type' => 'date', 'description' => 'Letter date'],
                    ['key' => 'subject', 'name' => 'Subject', 'type' => 'text', 'description' => 'Main topic or subject line'],
                ],
            ],
            [
                'key' => 'research',
                'name' => 'Research',
                'description' => 'Academic papers, studies, whitepapers, theses, scientific documents',
                'fields' => [
                    ['key' => 'title', 'name' => 'Title', 'type' => 'text', 'description' => 'Paper or document title'],
                    ['key' => 'authors', 'name' => 'Authors', 'type' => 'text', 'description' => 'Author names'],
                    ['key' => 'publication_date', 'name' => 'Publication Date', 'type' => 'date'],
                    ['key' => 'topic', 'name' => 'Topic/Field', 'type' => 'text', 'description' => 'Research area or field'],
                ],
            ],
            [
                'key' => 'human_resources',
                'name' => 'Human Resources',
                'description' => 'CVs, resumes, job applications, personnel files, employment documents',
                'fields' => [
                    ['key' => 'person_name', 'name' => 'Person Name', 'type' => 'text'],
                    ['key' => 'document_type', 'name' => 'Document Type', 'type' => 'enum', 'enum_values' => ['CV', 'Resume', 'Application', 'Contract', 'Performance Review', 'Other']],
                    ['key' => 'position', 'name' => 'Position/Role', 'type' => 'text'],
                    ['key' => 'date', 'name' => 'Date', 'type' => 'date'],
                ],
            ],
            [
                'key' => 'sales',
                'name' => 'Sales',
                'description' => 'Quotes, quotations, offers, orders, purchase documents, proposals',
                'fields' => [
                    ['key' => 'customer', 'name' => 'Customer', 'type' => 'text'],
                    ['key' => 'vendor', 'name' => 'Vendor', 'type' => 'text'],
                    ['key' => 'amount', 'name' => 'Amount', 'type' => 'number'],
                    ['key' => 'currency', 'name' => 'Currency', 'type' => 'enum', 'enum_values' => ['EUR', 'USD', 'GBP', 'CHF', 'Other']],
                    ['key' => 'date', 'name' => 'Date', 'type' => 'date'],
                    ['key' => 'status', 'name' => 'Status', 'type' => 'enum', 'enum_values' => ['Draft', 'Sent', 'Accepted', 'Rejected', 'Expired']],
                ],
            ],
        ];
    }
}
