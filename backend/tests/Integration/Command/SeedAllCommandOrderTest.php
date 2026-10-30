<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * app:seed runs the tagged seeders by priority. The order is a dependency
 * order (models before the defaults that reference them, module gates after
 * every flag), so it is pinned here: a new seeder must take its place in
 * this list on purpose, and a priority typo fails instead of reordering.
 */
final class SeedAllCommandOrderTest extends KernelTestCase
{
    private const ORDER = [
        'models', 'model-retirements', 'prompts', 'defaults', 'rate-limits',
        'subscriptions', 'multitask', 'mcp', 'media-jobs', 'branding', 'mobile',
        'marketing-news', 'usage-taximeter', 'progress-narration', 'updates',
        'messages-gateway', 'saved-tasks', 'research', 'file-context', 'desktop-agent', 'iam',
        'agents', 'bundle', 'platform-links', 'self-aware', 'structured-output',
        'embedding-router', 'native-tool-routing', 'document-tools', 'smart-search',
        'ai-pipeline', 'egress', 'plugs', 'tools', 'workflows', 'compute', 'module-gates', 'demo-widget',
    ];

    public function testSeedersRunInDependencyOrder(): void
    {
        $command = (new Application(self::bootKernel()))->find('app:seed');

        preg_match_all('/^\s+\d+\. (\S+)/m', $command->getHelp(), $matches);

        self::assertSame(self::ORDER, $matches[1]);
    }
}
