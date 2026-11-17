<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project;

use App\Entity\Chat;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\Project\ProjectChatScope;
use App\Service\Project\ProjectsConfig;
use PHPUnit\Framework\TestCase;

final class ProjectChatScopeTest extends TestCase
{
    public function testChatOfTheOwnerGetsFolderAndInstructions(): void
    {
        $project = new Project(7, 'Kitchen', 'u-1', 'project:u-1');
        $project->instruct('Answer in French');
        $context = $this->scope(true, $project)->forChat($this->chat(7, 5));

        self::assertNotNull($context);
        self::assertSame('project:u-1', $context->groupKey);
        self::assertSame('Answer in French', $context->instructions);
    }

    public function testFlagOffTreatsTheChatAsPlain(): void
    {
        $project = new Project(7, 'Kitchen', 'u-1', 'project:u-1');

        self::assertNull($this->scope(false, $project)->forChat($this->chat(7, 5)));
    }

    public function testPlainChatAndForeignProjectGetNothing(): void
    {
        $project = new Project(8, 'Other', 'u-2', 'project:u-2');

        self::assertNull($this->scope(true, $project)->forChat($this->chat(7, null)));
        self::assertNull($this->scope(true, $project)->forChat($this->chat(7, 5)), 'a project of another account is not honoured');
        self::assertNull($this->scope(true, null)->forChat($this->chat(7, 5)));
        self::assertNull($this->scope(true, $project)->forChat(null));
    }

    private function chat(int $userId, ?int $projectId): Chat
    {
        $chat = new Chat();
        $chat->setUserId($userId);
        $chat->setProjectId($projectId);

        return $chat;
    }

    private function scope(bool $enabled, ?Project $project): ProjectChatScope
    {
        $config = $this->createStub(ProjectsConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('instructionsMaxChars')->willReturn(4000);
        $repo = $this->createStub(ProjectRepository::class);
        $repo->method('findById')->willReturn($project);

        return new ProjectChatScope($config, $repo);
    }
}
