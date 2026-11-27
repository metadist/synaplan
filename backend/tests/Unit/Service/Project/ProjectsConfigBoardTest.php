<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Repository\ConfigRepository;
use App\Service\Project\ProjectsConfig;
use PHPUnit\Framework\TestCase;

final class ProjectsConfigBoardTest extends TestCase
{
    public function testTheBoardShipsOff(): void
    {
        self::assertFalse($this->config(['ENABLED' => '1'])->isBoardEnabled());
    }

    public function testTheBoardNeedsProjects(): void
    {
        self::assertFalse($this->config(['ENABLED' => '0', 'BOARD_ENABLED' => '1'])->isBoardEnabled());
        self::assertFalse($this->config([], '0', '1')->isBoardEnabled());
    }

    public function testTheStoredSwitchOrTheEnvironmentTurnsItOn(): void
    {
        self::assertTrue($this->config(['ENABLED' => '1', 'BOARD_ENABLED' => '1'])->isBoardEnabled());
        self::assertTrue($this->config(['ENABLED' => '1'], '', 'true')->isBoardEnabled());
        self::assertFalse($this->config(['ENABLED' => '1', 'BOARD_ENABLED' => '1'], '', 'off')->isBoardEnabled());
    }

    public function testMaxItemsFallsBackToTheDefault(): void
    {
        self::assertSame(ProjectsConfig::DEFAULT_MAX_ITEMS, $this->config([])->maxItems());
        self::assertSame(5, $this->config(['MAX_ITEMS' => '5'])->maxItems());
        self::assertSame(ProjectsConfig::DEFAULT_MAX_ITEMS, $this->config(['MAX_ITEMS' => '-3'])->maxItems());
    }

    /**
     * @param array<string, string> $stored
     */
    private function config(array $stored, string $env = '', string $boardEnv = ''): ProjectsConfig
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getValue')->willReturnCallback(static fn (int $owner, string $group, string $setting): ?string => $stored[$setting] ?? null);

        return new ProjectsConfig($repository, $env, $boardEnv);
    }
}
