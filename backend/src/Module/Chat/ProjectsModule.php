<?php

declare(strict_types=1);

namespace App\Module\Chat;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Service\Project\ProjectChatMembership;
use App\Service\Project\ProjectChatScope;
use App\Service\Project\ProjectsConfig;
use App\Service\Project\ProjectService;
use App\Service\Project\ProjectSourceIndexer;
use App\Service\Project\ProjectSourceService;

/**
 * Projects: a name, instructions and sources, and the chats that work with
 * them. Off means the project routes answer 404 and a chat in a project is a
 * plain chat.
 */
final class ProjectsModule implements FeatureModuleInterface
{
    public const ID = 'projects';

    public function __construct(private readonly ProjectsConfig $config)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.projects.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(
            envKeys: ['PROJECTS_ENABLED'],
            bconfigKeys: [ProjectsConfig::GROUP.'.'.ProjectsConfig::ENABLED],
        );
    }

    public function isConfigured(): bool
    {
        return $this->config->isEnabled();
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('Projects are turned off');
        }

        return new ModuleStatus(configured: true, healthy: true, message: 'Projects are on');
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return [
            'api_projects_list',
            'api_projects_create',
            'api_projects_get',
            'api_projects_update',
            'api_projects_delete',
            'api_projects_sources_list',
            'api_projects_sources_add',
            'api_projects_sources_refresh',
            'api_projects_sources_remove',
            'api_projects_chats_list',
            'api_projects_chats_create',
            'api_projects_chats_join',
            'api_projects_chats_leave',
        ];
    }

    public function serviceIds(): array
    {
        return [
            ProjectsConfig::class,
            ProjectService::class,
            ProjectSourceService::class,
            ProjectSourceIndexer::class,
            ProjectChatScope::class,
            ProjectChatMembership::class,
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/projects';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}
