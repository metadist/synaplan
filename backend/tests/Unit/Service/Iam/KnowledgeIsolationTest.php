<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\Share;
use App\Repository\ChatRepository;
use App\Repository\FileRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\MessageRepository;
use App\Repository\PromptRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\IamConfig;
use App\Service\Iam\ResourceKind\AssistantKind;
use App\Service\Iam\ResourceKind\ConversationKind;
use App\Service\Iam\ResourceKind\KnowledgeFolderKind;
use App\Service\RAG\RagScopeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * C2: no share ⇒ own scope only; read-only share ⇒ no extra RAG scope;
 * use share ⇒ a foreign owner scope; sharing off ⇒ own scope only.
 */
final class KnowledgeIsolationTest extends TestCase
{
    private IamConfig&MockObject $iamConfig;
    private ShareRepository&MockObject $shares;
    private RagScopeResolver $resolver;

    protected function setUp(): void
    {
        $this->iamConfig = $this->createMock(IamConfig::class);
        $this->shares = $this->createMock(ShareRepository::class);
        $members = $this->createMock(GroupMemberRepository::class);
        $members->method('findByUserId')->willReturn([]);
        $this->resolver = new RagScopeResolver(
            $this->iamConfig,
            $this->shares,
            $members,
            $this->createMock(ChatRepository::class),
            $this->createMock(MessageRepository::class),
            $this->createMock(FileRepository::class),
            $this->createMock(PromptRepository::class),
        );
    }

    public function testSharingOffReturnsOwnScopeOnly(): void
    {
        $this->iamConfig->method('isSharingEnabled')->willReturn(false);
        $this->shares->expects(self::never())->method('findForSubjects');

        $scopes = $this->resolver->resolve(3, null);

        self::assertCount(1, $scopes);
        self::assertSame(3, $scopes[0]->ownerId);
        self::assertNull($scopes[0]->groupKey);
    }

    public function testReadOnlyFolderShareDoesNotAddScope(): void
    {
        $this->iamConfig->method('isSharingEnabled')->willReturn(true);
        $share = $this->folderShare('9:sales', 'read');
        $this->shares->method('findForSubjects')->willReturn([$share]);

        $scopes = $this->resolver->resolve(3, null);

        self::assertCount(1, $scopes);
        self::assertSame(3, $scopes[0]->ownerId);
    }

    public function testUseFolderShareAddsOwnerScope(): void
    {
        $this->iamConfig->method('isSharingEnabled')->willReturn(true);
        $share = $this->folderShare('9:sales', 'use');
        $this->shares->method('findForSubjects')->willReturnCallback(
            static function (int $_userId, array $_groups, ?string $kind) use ($share): array {
                return KnowledgeFolderKind::KEY === $kind ? [$share] : [];
            }
        );

        $scopes = $this->resolver->resolve(3, null);

        self::assertCount(2, $scopes);
        self::assertSame(3, $scopes[0]->ownerId);
        self::assertSame(9, $scopes[1]->ownerId);
        self::assertSame('sales', $scopes[1]->groupKey);
        self::assertSame([], $scopes[1]->fileIds);
    }

    public function testUseConversationShareLimitsToReferencedFiles(): void
    {
        $this->iamConfig->method('isSharingEnabled')->willReturn(true);

        $chat = new Chat();
        $chat->setUserId(9);
        (new \ReflectionProperty(Chat::class, 'id'))->setValue($chat, 5);

        $file = (new File())
            ->setUserId(9)
            ->setGroupKey('sales')
            ->setFileName('one.pdf');
        (new \ReflectionProperty(File::class, 'id'))->setValue($file, 42);

        $message = new Message();
        $message->addFile($file);

        $chatRepo = $this->createMock(ChatRepository::class);
        $chatRepo->expects(self::once())->method('find')->with(5)->willReturn($chat);

        $messages = $this->createMock(MessageRepository::class);
        $messages->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($message): array {
                return isset($criteria['chatId']) ? [$message] : [];
            }
        );

        $files = $this->createMock(FileRepository::class);
        $files->method('findBy')->willReturn([]);

        $members = $this->createMock(GroupMemberRepository::class);
        $members->method('findByUserId')->willReturn([]);

        $share = new Share();
        $share->setResourceKind(ConversationKind::KEY);
        $share->setResourceId('5');
        $share->setSubjectType(Share::SUBJECT_USER);
        $share->setSubjectId(3);
        $share->setPermission('use');

        $this->shares->method('findForSubjects')->willReturnCallback(
            static function (int $_userId, array $_groups, ?string $kind) use ($share): array {
                return ConversationKind::KEY === $kind ? [$share] : [];
            }
        );

        $resolver = new RagScopeResolver(
            $this->iamConfig,
            $this->shares,
            $members,
            $chatRepo,
            $messages,
            $files,
            $this->createMock(PromptRepository::class),
        );

        $scopes = $resolver->resolve(3, null);

        self::assertCount(2, $scopes);
        self::assertSame(3, $scopes[0]->ownerId);
        self::assertSame(9, $scopes[1]->ownerId);
        self::assertSame('sales', $scopes[1]->groupKey);
        self::assertSame([42], $scopes[1]->fileIds);
    }

    public function testAssistantFolderFollowsAssistantShare(): void
    {
        $this->iamConfig->method('isSharingEnabled')->willReturn(true);

        $prompt = new Prompt();
        $prompt->setOwnerId(9);
        $prompt->setTopic('sales-helper');
        $prompt->setPrompt('Help sales.');

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::atLeastOnce())
            ->method('find')
            ->with(8)
            ->willReturn($prompt);

        $share = new Share();
        $share->setResourceKind(AssistantKind::KEY);
        $share->setResourceId('8');
        $share->setSubjectType(Share::SUBJECT_USER);
        $share->setSubjectId(3);
        $share->setPermission('use');

        $shareRows = [$share];
        $this->shares->method('findForSubjects')->willReturnCallback(
            static function (int $_userId, array $_groups, ?string $kind) use (&$shareRows): array {
                return AssistantKind::KEY === $kind ? $shareRows : [];
            }
        );

        $members = $this->createMock(GroupMemberRepository::class);
        $members->method('findByUserId')->willReturn([]);

        $resolver = new RagScopeResolver(
            $this->iamConfig,
            $this->shares,
            $members,
            $this->createMock(ChatRepository::class),
            $this->createMock(MessageRepository::class),
            $this->createMock(FileRepository::class),
            $prompts,
        );

        $scopes = $resolver->resolve(3, null);

        self::assertCount(2, $scopes);
        self::assertSame(3, $scopes[0]->ownerId);
        self::assertSame(9, $scopes[1]->ownerId);
        self::assertSame('TASKPROMPT:sales-helper', $scopes[1]->groupKey);

        $shareRows = [];
        $revoked = $resolver->resolve(3, null);
        self::assertCount(1, $revoked);
        self::assertSame(3, $revoked[0]->ownerId);
    }

    private function folderShare(string $resourceId, string $permission): Share
    {
        $share = new Share();
        $share->setResourceKind(KnowledgeFolderKind::KEY);
        $share->setResourceId($resourceId);
        $share->setSubjectType(Share::SUBJECT_USER);
        $share->setSubjectId(3);
        $share->setPermission($permission);

        return $share;
    }
}
