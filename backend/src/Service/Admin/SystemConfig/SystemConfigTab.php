<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One tab of Admin → System settings and every field it shows. The tag
 * priority is the tab's position; a new setting is one field in its tab.
 */
#[AutoconfigureTag(self::TAG)]
interface SystemConfigTab
{
    public const TAG = 'app.system_config.tab';

    public function id(): string;

    public function label(): string;

    /**
     * @return array<string, ConfigSection> by section id, in the order the tab shows them
     */
    public function sections(): array;
}
