<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Email tab: the primary mailer and the sender and admin addresses, read
 * from the environment (a change needs a restart).
 */
#[AsTaggedItem(priority: 120)]
final readonly class EmailTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function sections(): array
    {
        return [
            'mailer' => $this->mailer(),
        ];
    }

    private function mailer(): ConfigSection
    {
        return new ConfigSection('Primary Mailer', [
            'MAILER_DSN' => new FieldDefinition(
                type: 'text',
                description: 'SMTP connection string',
                default: 'null://null',
                sensitive: true,
            ),
            'APP_SENDER_EMAIL' => new FieldDefinition(
                type: 'email',
                description: 'Sender email address',
            ),
            'APP_SENDER_NAME' => new FieldDefinition(
                type: 'text',
                description: 'Sender name',
                default: 'Synaplan',
            ),
            'APP_ADMIN_EMAIL' => new FieldDefinition(
                type: 'email',
                description: 'Operator inbox for incident + content-moderation alerts',
            ),
        ]);
    }
}
