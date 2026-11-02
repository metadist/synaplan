<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AgentTemplate;

use App\Entity\Agent;
use App\Entity\Connection;
use App\Entity\InboundEmailHandler;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ConnectionRepository;
use App\Repository\FileRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\ModelRepository;
use App\Service\AgentTemplate\AgentTemplateRequirementChecker;
use PHPUnit\Framework\TestCase;

final class AgentTemplateRequirementCheckerTest extends TestCase
{
    public function testMissingChatModelIsUnavailableAndAFolderStillCanBeChosen(): void
    {
        $models = $this->createStub(ModelRepository::class);
        $models->method('findByTag')->willReturn([]);
        $checker = $this->checker($models, [], []);

        $rows = $checker->check([
            ['kind' => 'capability', 'value' => 'chat', 'hintKey' => null, 'optional' => false],
            ['kind' => 'knowledgeFolder', 'value' => null, 'hintKey' => 'templates.offerWriter.needsPriceList', 'optional' => false],
        ], $this->user(), null);

        self::assertSame('unavailable', $rows[0]['state']);
        self::assertNull($rows[0]['action']);
        self::assertSame('needsYou', $rows[1]['state']);
        self::assertSame('chooseFolder', $rows[1]['action']);
    }

    public function testInactiveChatModelDoesNotSatisfyCapability(): void
    {
        $inactive = $this->createStub(Model::class);
        $inactive->method('getActive')->willReturn(0);
        $models = $this->createStub(ModelRepository::class);
        $models->method('findByTag')->willReturn([$inactive]);
        $checker = $this->checker($models, [], []);

        $rows = $checker->check([
            ['kind' => 'capability', 'value' => 'chat', 'hintKey' => null, 'optional' => false],
        ], $this->user(), null);

        self::assertSame('unavailable', $rows[0]['state']);
    }

    public function testAChosenFolderAndAMailboxSatisfyTheRequirements(): void
    {
        $activeModel = $this->createStub(Model::class);
        $activeModel->method('getActive')->willReturn(1);
        $models = $this->createStub(ModelRepository::class);
        $models->method('findByTag')->willReturn([$activeModel]);
        $m365 = new Connection(3, Connection::TYPE_M365, 'Work M365');
        $m365->setStatus(Connection::STATUS_CONNECTED);
        $checker = $this->checker($models, [], [$m365]);
        $agent = new Agent(3, 1, 'offer-writer', 'Offer writer', [
            'knowledge' => ['folders' => ['prices'], 'fileIds' => []],
        ]);

        $rows = $checker->check([
            ['kind' => 'capability', 'value' => 'chat', 'hintKey' => null, 'optional' => false],
            ['kind' => 'knowledgeFolder', 'value' => null, 'hintKey' => null, 'optional' => false],
            ['kind' => 'connection', 'value' => 'mail', 'hintKey' => null, 'optional' => true],
        ], $this->user(), $agent);

        self::assertSame(['ready', 'ready', 'ready'], array_column($rows, 'state'));
    }

    public function testDisconnectedM365DoesNotSatisfyMailRequirement(): void
    {
        $models = $this->createStub(ModelRepository::class);
        $disconnected = new Connection(3, Connection::TYPE_M365, 'Work M365');
        $disconnected->setStatus(Connection::STATUS_DISCONNECTED);
        $reauth = new Connection(3, Connection::TYPE_M365, 'Work M365 2');
        $reauth->setStatus(Connection::STATUS_REAUTH_REQUIRED);
        $checker = $this->checker($models, [], [$disconnected, $reauth]);

        $rows = $checker->check([
            ['kind' => 'connection', 'value' => 'mail', 'hintKey' => null, 'optional' => true],
        ], $this->user(), null);

        self::assertSame('needsYou', $rows[0]['state']);
    }

    public function testUploadedOwnFolderFilesSatisfyKnowledgeRequirement(): void
    {
        $models = $this->createStub(ModelRepository::class);
        $files = $this->createMock(FileRepository::class);
        $files->expects(self::once())->method('existsForUserAndGroupKey')->with(3, 'TASKPROMPT:agent:offer-writer')->willReturn(true);
        $checker = $this->checker($models, [], [], $files);
        $agent = new Agent(3, 1, 'offer-writer', 'Offer writer', [
            'knowledge' => ['ownFolder' => true, 'folders' => [], 'fileIds' => []],
        ]);

        $rows = $checker->check([
            ['kind' => 'knowledgeFolder', 'value' => null, 'hintKey' => null, 'optional' => false],
        ], $this->user(), $agent);

        self::assertSame('ready', $rows[0]['state']);
    }

    /**
     * @param list<InboundEmailHandler> $handlers
     * @param list<Connection>          $connectionsList
     */
    private function checker(
        ModelRepository $models,
        array $handlers,
        array $connectionsList,
        ?FileRepository $files = null,
    ): AgentTemplateRequirementChecker {
        $connections = $this->createStub(ConnectionRepository::class);
        $connections->method('findByOwner')->willReturn($connectionsList);
        $mailboxes = $this->createStub(InboundEmailHandlerRepository::class);
        $mailboxes->method('findActiveByUser')->willReturn($handlers);

        return new AgentTemplateRequirementChecker($models, $connections, $mailboxes, $files);
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(3);

        return $user;
    }
}
