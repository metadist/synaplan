<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Auth\RegistrationConfig;
use App\Service\Iam\IamConfig;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Sharing tab: who may share with everyone, the directory groups, admin
 * impersonation and the audit retention. BCONFIG group IAM (owner 0), no
 * restart.
 */
#[AsTaggedItem(priority: 50)]
final readonly class SharingTab implements SystemConfigTab
{
    public function __construct(
        private RegistrationConfig $registrationConfig,
    ) {
    }

    public function id(): string
    {
        return 'sharing';
    }

    public function label(): string
    {
        return 'Sharing';
    }

    public function sections(): array
    {
        return [
            'everyone' => $this->everyone(),
            'directory' => $this->directory(),
            'audit' => $this->audit(),
        ];
    }

    private function everyone(): ConfigSection
    {
        return new ConfigSection('Everyone', [
            'IAM_EVERYONE_SHARES' => new FieldDefinition(
                type: 'select',
                description: 'Who may share with everyone who has an account. On an instance where anyone can sign up, that is every registered account. "any_owner" lets every owner do it. "admins_only" lets only administrators create those shares; shares that already exist keep working. "disabled" removes that audience: new shares are refused, and shares people created earlier grant nothing until you pick another value — the people they reached lose access without being told. Nothing is deleted, so switching back restores those shares. System assistants and plugin-pack assistants stay available to every account on every value. A public instance should use "disabled"; an install that is one organization can use "any_owner".',
                // Mirrors IamConfigSeeder and IamConfig::everyoneSharesFallback(): the value a
                // fresh install gets when nothing is stored, so the UI does not claim a default
                // the seeder would not have written here.
                default: $this->registrationConfig->isEnabled()
                    ? IamConfig::EVERYONE_SHARES_DISABLED
                    : IamConfig::EVERYONE_SHARES_ANY_OWNER,
                row: new DatabaseRow(IamConfig::CONFIG_GROUP, IamConfig::KEY_EVERYONE_SHARES),
                options: [IamConfig::EVERYONE_SHARES_ANY_OWNER, IamConfig::EVERYONE_SHARES_ADMINS_ONLY, IamConfig::EVERYONE_SHARES_DISABLED],
            ),
        ]);
    }

    private function directory(): ConfigSection
    {
        return new ConfigSection('Directory groups', [
            'IAM_DIRECTORY_GROUPS_CLAIM' => new FieldDefinition(
                type: 'text',
                description: 'Dotted claim path for directory groups (default: groups). Same resolver as OIDC roles; comma-separated paths are allowed.',
                default: IamConfig::DEFAULT_DIRECTORY_GROUPS_CLAIM,
                row: new DatabaseRow(IamConfig::CONFIG_GROUP, IamConfig::KEY_DIRECTORY_GROUPS_CLAIM),
            ),
            'IAM_DIRECTORY_GROUP_NAMES' => new FieldDefinition(
                type: 'text',
                description: 'Optional JSON map of claim value to display name, e.g. {"sales":"Sales"}. Empty object keeps the claim value as the group name.',
                default: '{}',
                row: new DatabaseRow(IamConfig::CONFIG_GROUP, IamConfig::KEY_DIRECTORY_GROUP_NAMES),
            ),
        ]);
    }

    private function audit(): ConfigSection
    {
        return new ConfigSection('Users & audit', [
            'IAM_ADMIN_IMPERSONATION' => new FieldDefinition(
                type: 'select',
                description: 'Whether administrators may view the application as another user. "audited" writes an audit row (default). "disabled" blocks the action.',
                default: IamConfig::IMPERSONATION_AUDITED,
                row: new DatabaseRow(IamConfig::CONFIG_GROUP, IamConfig::KEY_ADMIN_IMPERSONATION),
                options: [IamConfig::IMPERSONATION_AUDITED, IamConfig::IMPERSONATION_DISABLED],
            ),
            'IAM_AUDIT_RETENTION_DAYS' => new FieldDefinition(
                type: 'number',
                description: 'Days to keep Users audit rows. Default 365. 0 keeps them forever. Run app:iam:reap-audit on a schedule to apply this.',
                default: (string) IamConfig::DEFAULT_AUDIT_RETENTION_DAYS,
                row: new DatabaseRow(IamConfig::CONFIG_GROUP, IamConfig::KEY_AUDIT_RETENTION_DAYS),
            ),
        ]);
    }
}
