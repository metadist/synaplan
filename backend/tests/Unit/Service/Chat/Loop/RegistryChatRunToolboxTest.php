<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\AI\Messages\Tools\KnowledgeSearchTool;
use App\Entity\Approval;
use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ApprovalRepository;
use App\Service\Chat\Loop\ChatRunApprovals;
use App\Service\Chat\Loop\ChatRunScope;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunStep;
use App\Service\Chat\Loop\ConnectedToolCatalog;
use App\Service\Chat\Loop\RegistryChatRunToolbox;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\Project\ProjectTurnContext;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RegistryChatRunToolboxTest extends TestCase
{
    private const RUN_ID = '01JD6Q3Z8Y9X7W6V5T4S3R2Q1P';

    public function testOnlyWebToolsAndThePersonsOwnToolsAreOffered(): void
    {
        $toolbox = $this->toolbox(registry: true);

        $names = array_map(static fn (array $d): string => (string) $d['function']['name'], $toolbox->declarations($this->scope()));

        self::assertSame(['web_search', ConnectedToolCatalog::NAME], $names, 'the own tools stay behind the catalog until opened');
        self::assertSame(['web_search', 'custom_order', ConnectedToolCatalog::NAME], array_map(static fn (array $d): string => (string) $d['function']['name'], $toolbox->declarations($this->scope(), ['custom_order'])));
    }

    public function testTheCatalogListsTheOwnToolsAndOpensOnlyThose(): void
    {
        $toolbox = $this->toolbox(registry: true);
        $state = $this->state();

        $listing = $toolbox->run($this->scope(), $state, ['id' => 'c1', 'name' => ConnectedToolCatalog::NAME, 'args' => []]);
        $opened = $toolbox->run($this->scope(), $state, ['id' => 'c2', 'name' => ConnectedToolCatalog::NAME, 'args' => ['use' => ['custom_order', 'web_search', 'code_run']]]);

        self::assertStringContainsString('- custom_order', $listing->text);
        self::assertStringNotContainsString('web_search', $listing->text);
        self::assertSame(['custom_order'], $state->opened);
        self::assertStringContainsString('Not in the list: web_search, code_run.', $opened->text);
        self::assertTrue($toolbox->isRead(ConnectedToolCatalog::NAME));
    }

    public function testNoCatalogWithoutOwnTools(): void
    {
        $names = array_map(static fn (array $d): string => (string) $d['function']['name'], $this->toolbox(registry: true, own: false)->declarations($this->scope()));

        self::assertSame(['web_search'], $names);
    }

    public function testNothingFromTheRegistryWhileItIsOff(): void
    {
        self::assertSame([], $this->toolbox(registry: false)->declarations($this->scope()));
    }

    public function testTheProjectSourcesAreSearchedInTheFolderOfThisUnit(): void
    {
        $knowledge = $this->createMock(KnowledgeSearchTool::class);
        $knowledge->method('declaration')->willReturn(['name' => KnowledgeSearchTool::NAME, 'description' => 'x', 'input_schema' => ['type' => 'object']]);
        $knowledge->expects(self::once())->method('executeInFolder')
            ->with(['query' => 'launch'], self::anything(), 'project-key')
            ->willReturn(['text' => 'chunk', 'isError' => false, 'summary' => '']);
        $toolbox = $this->toolbox(registry: false, knowledge: $knowledge);
        $scope = $this->scope(new ProjectTurnContext('project-key', 'Launch', null));

        $names = array_map(static fn (array $d): string => (string) $d['function']['name'], $toolbox->declarations($scope));
        $result = $toolbox->run($scope, $this->state(), ['id' => 'c1', 'name' => KnowledgeSearchTool::NAME, 'args' => ['query' => 'launch']]);

        self::assertSame([KnowledgeSearchTool::NAME], $names);
        self::assertSame('chunk', $result->text);
    }

    public function testAChatOutsideAProjectCannotSearchSources(): void
    {
        $knowledge = $this->createMock(KnowledgeSearchTool::class);
        $knowledge->expects(self::never())->method('executeInFolder');
        $toolbox = $this->toolbox(registry: true, knowledge: $knowledge);

        $result = $toolbox->run($this->scope(), $this->state(), ['id' => 'c1', 'name' => KnowledgeSearchTool::NAME, 'args' => ['query' => 'x']]);

        self::assertSame(RegistryChatRunToolbox::NOT_AVAILABLE, $result->text);
    }

    public function testAToolThatIsNotOfferedIsNotRun(): void
    {
        $invoker = $this->createMock(ToolInvoker::class);
        $invoker->expects(self::never())->method('invoke');
        $toolbox = $this->toolbox(registry: true, invoker: $invoker);

        $result = $toolbox->run($this->scope(), $this->state(), ['id' => 'c1', 'name' => 'code_run', 'args' => []]);

        self::assertSame(RegistryChatRunToolbox::NOT_AVAILABLE, $result->text);
    }

    /**
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function foreignApprovals(): iterable
    {
        yield 'another owner' => [8, 'chat_run:'.self::RUN_ID, 'custom:order', Approval::STATUS_APPROVED];
        yield 'another run' => [7, 'chat_run:01JD6Q3Z8Y9X7W6V5T4S3R2Q1Q', 'custom:order', Approval::STATUS_APPROVED];
        yield 'another tool' => [7, 'chat_run:'.self::RUN_ID, 'custom:other', Approval::STATUS_APPROVED];
        yield 'not approved' => [7, 'chat_run:'.self::RUN_ID, 'custom:order', Approval::STATUS_PENDING];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignApprovals')]
    public function testAnApprovalRunsOnlyItsOwnCall(int $owner, string $requestedBy, string $tool, string $status): void
    {
        $approval = new Approval($owner, $requestedBy, $tool, 'write', time() + 3600);
        if (Approval::STATUS_APPROVED === $status) {
            $approval->markApproved($owner);
        }
        $repo = $this->createStub(ApprovalRepository::class);
        $repo->method('find')->willReturn($approval);
        $invoker = $this->createMock(ToolInvoker::class);
        $invoker->expects(self::never())->method('invoke');
        $toolbox = $this->toolbox(registry: true, invoker: $invoker, approvals: $repo);

        $result = $toolbox->run($this->scope(), $this->state(), ['id' => 'c1', 'name' => 'custom_order', 'args' => []], 77);

        self::assertSame(ChatRunStep::DECLINED, $result->text);
    }

    public function testAnApprovedCallThatAlreadyRanIsNotRunAgainAndNotCalledDeclined(): void
    {
        $approval = new Approval(7, 'chat_run:'.self::RUN_ID, 'custom:order', 'write', time() + 3600);
        $approval->markApproved(7);
        $approval->markExecuted('chat_run');
        $repo = $this->createStub(ApprovalRepository::class);
        $repo->method('find')->willReturn($approval);
        $invoker = $this->createMock(ToolInvoker::class);
        $invoker->expects(self::never())->method('invoke');
        $toolbox = $this->toolbox(registry: true, invoker: $invoker, approvals: $repo);

        $result = $toolbox->run($this->scope(), $this->state(), ['id' => 'c1', 'name' => 'custom_order', 'args' => []], 77);

        self::assertSame(RegistryChatRunToolbox::ALREADY_RAN, $result->text);
    }

    public function testAToolNamedLikeALoopToolIsNotOffered(): void
    {
        $toolbox = $this->toolbox(registry: true, extra: [self::descriptor('finish', ToolSource::Custom), self::descriptor('update_plan', ToolSource::Mcp)]);

        $names = array_map(static fn (array $d): string => (string) $d['function']['name'], $toolbox->declarations($this->scope(), ['finish', 'update_plan', 'custom_order']));

        self::assertSame(['web_search', 'custom_order', ConnectedToolCatalog::NAME], $names);
    }

    /**
     * @param list<ToolDescriptor> $extra
     */
    private function toolbox(bool $registry, ?KnowledgeSearchTool $knowledge = null, ?ToolInvoker $invoker = null, ?ApprovalRepository $approvals = null, array $extra = [], bool $own = true): RegistryChatRunToolbox
    {
        $tools = $this->createStub(ToolRegistry::class);
        $tools->method('forUser')->willReturn([
            self::descriptor('web_search', ToolSource::Builtin),
            self::descriptor('code_run', ToolSource::Builtin),
            self::descriptor('document_edit', ToolSource::Document),
            ...($own ? [self::descriptor('custom:order', ToolSource::Custom)] : []),
            ...$extra,
        ]);
        $config = $this->createStub(ToolsConfig::class);
        $config->method('isRegistryEnabled')->willReturn($registry);
        if (null === $knowledge) {
            $knowledge = $this->createStub(KnowledgeSearchTool::class);
            $knowledge->method('declaration')->willReturn(['name' => KnowledgeSearchTool::NAME, 'description' => 'x', 'input_schema' => ['type' => 'object']]);
        }

        return new RegistryChatRunToolbox(
            $tools,
            $config,
            $invoker ?? $this->createStub(ToolInvoker::class),
            $knowledge,
            $this->createStub(MessagesGatewayConfig::class),
            new ChatRunApprovals($approvals ?? $this->createStub(ApprovalRepository::class), new MockClock('2026-11-20 09:00:00')),
        );
    }

    private static function descriptor(string $name, ToolSource $source): ToolDescriptor
    {
        return new ToolDescriptor($name, $name, $name, ['type' => 'object'], ToolSource::Builtin === $source ? SideEffect::Read : SideEffect::Write, $source, 7);
    }

    private function scope(?ProjectTurnContext $project = null): ChatRunScope
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return new ChatRunScope($user, $this->createStub(Chat::class), $project);
    }

    private function state(): ChatRunState
    {
        return new ChatRunState(self::RUN_ID, 7, 11, null, null, null, 'en', '', [], 1);
    }
}
