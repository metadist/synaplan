<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\Project;
use App\Service\Project\ProjectPresenter;
use PHPUnit\Framework\TestCase;

final class ProjectPinTest extends TestCase
{
    public function testPinAndUnpinDoNotCountAsAChange(): void
    {
        $project = new Project(7, 'Kitchen', 'u-1', 'project:u-1');
        $changed = $project->getUpdated();

        self::assertFalse($project->isPinned());
        $project->pin(true);
        self::assertTrue($project->isPinned());
        $project->pin(false);
        self::assertFalse($project->isPinned());
        self::assertSame($changed, $project->getUpdated());
    }

    public function testPinningTwiceKeepsTheFirstPinTime(): void
    {
        $project = new Project(7, 'Kitchen', 'u-1', 'project:u-1');
        $project->pin(true);
        $first = (new \ReflectionProperty(Project::class, 'pinnedAt'))->getValue($project);
        $project->pin(true);

        self::assertSame($first, (new \ReflectionProperty(Project::class, 'pinnedAt'))->getValue($project));
    }

    public function testPresenterShowsThePin(): void
    {
        $project = new Project(7, 'Kitchen', 'u-1', 'project:u-1');
        $presenter = new ProjectPresenter();

        self::assertFalse($presenter->project($project)['pinned']);
        $project->pin(true);
        self::assertTrue($presenter->project($project)['pinned']);
    }
}
