<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\Chat;
use App\Entity\Project;
use App\Repository\ChatRepository;
use App\Service\Project\ProjectChatMembership;
use App\Service\Project\ProjectException;
use App\Service\Project\ProjectsConfig;
use App\Service\Project\ProjectService;
use PHPUnit\Framework\TestCase;

final class ProjectChatMembershipTest extends TestCase
{
    public function testStartCreatesAWebChatOfTheOwnerInTheProject(): void
    {
        $chat = $this->membership()->start($this->project(), '  Tiles  ');

        self::assertSame(7, $chat->getUserId());
        self::assertSame(42, $chat->getProjectId());
        self::assertSame('Tiles', $chat->getTitle());
    }

    public function testAChatOfAnotherChannelCannotJoin(): void
    {
        $chat = (new Chat())->setUserId(7)->setSource('whatsapp');

        $this->expectExceptionCode(ProjectException::INVALID);
        $this->membership()->move($chat, 'u-1');
    }

    public function testLeavingClearsTheProject(): void
    {
        $chat = (new Chat())->setUserId(7)->setProjectId(42);

        $this->membership()->move($chat, null);

        self::assertNull($chat->getProjectId());
    }

    private function project(): Project
    {
        $project = new Project(7, 'Kitchen', 'u-1', 'project:u-1');
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 42);

        return $project;
    }

    private function membership(): ProjectChatMembership
    {
        $projects = $this->createStub(ProjectService::class);
        $projects->method('get')->willReturn($this->project());
        $config = $this->createStub(ProjectsConfig::class);
        $config->method('isEnabled')->willReturn(true);

        return new ProjectChatMembership($projects, $config, $this->createStub(ChatRepository::class));
    }
}
