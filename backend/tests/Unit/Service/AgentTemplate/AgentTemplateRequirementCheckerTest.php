<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AgentTemplate;

use App\Entity\Agent;
use App\Entity\Connection;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ConnectionRepository;
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
        $checker = $this->checker($models, [], null);

        $rows = $checker->check([
            ['kind' => 'capability', 'value' => 'chat', 'hintKey' => null, 'optional' => false],
            ['kind' => 'knowledgeFolder', 'value' => null, 'hintKey' => 'templates.offerWriter.needsPriceList', 'optional' => false],
        ], $this->user(), null);

        self::assertSame('unavailable', $rows[0]['state']);
        self::assertNull($rows[0]['action']);
        self::assertSame('needsYou', $rows[1]['state']);
        self::assertSame('chooseFolder', $rows[1]['action']);
    }

    public function testAChosenFolderAndAMailboxSatisfyTheRequirements(): void
    {
        $models = $this->createStub(ModelRepository::class);
        $models->method('findByTag')->willReturn([$this->createStub(Model::class)]);
        $mail = $this->createStub(Connection::class);
        $checker = $this->checker($models, [], $mail);
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

    private function checker(ModelRepository $models, array $handlers, ?Connection $mail): AgentTemplateRequirementChecker
    {
        $connections = $this->createStub(ConnectionRepository::class);
        $connections->method('findOneByOwnerAndType')->willReturnCallback(
            static fn (int $owner, string $type): ?Connection => 'mailbox' === $type ? $mail : null,
        );
        $mailboxes = $this->createStub(InboundEmailHandlerRepository::class);
        $mailboxes->method('findByUser')->willReturn($handlers);

        return new AgentTemplateRequirementChecker($models, $connections, $mailboxes);
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(3);

        return $user;
    }
}
