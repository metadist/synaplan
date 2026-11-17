<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Permissions;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\Permissions\McpToolPermissionException;
use App\Service\Mcp\Permissions\McpToolPermissions;
use App\Service\Mcp\Permissions\McpToolPolicyStore;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use PHPUnit\Framework\TestCase;

final class McpToolPolicyStoreTest extends TestCase
{
    private const CLASSES = [
        'search' => SideEffect::Read,
        'create' => SideEffect::Write,
        'update' => SideEffect::Write,
        'delete' => SideEffect::Destructive,
    ];

    private McpToolPolicyStore $store;

    protected function setUp(): void
    {
        $this->store = new McpToolPolicyStore($this->createStub(McpServerConfigRepository::class), new McpToolPermissions());
    }

    public function testStoresOnlyChoicesThatDifferFromTheClassDefault(): void
    {
        $server = $this->server();

        $this->store->setTools($server, ['create' => PolicyOutcome::Approve, 'search' => PolicyOutcome::Auto, 'delete' => PolicyOutcome::Approve], self::CLASSES);

        self::assertSame(['create' => 'approve'], $this->tools($server));
    }

    public function testDefaultAndTheDefaultValueBothRemoveTheOverride(): void
    {
        $server = $this->server('{"v":1,"tools":{"create":"block","delete":"auto"},"seen":[]}');

        $this->store->setTools($server, ['create' => null, 'delete' => PolicyOutcome::Approve], self::CLASSES);

        self::assertSame([], $this->tools($server));
    }

    public function testACategoryChangesEveryToolOfThatClassOnly(): void
    {
        $server = $this->server();

        $this->store->setCategory($server, SideEffect::Write, PolicyOutcome::Block, self::CLASSES);

        self::assertSame(['create' => 'block', 'update' => 'block'], $this->tools($server));

        $this->store->setCategory($server, SideEffect::Write, null, self::CLASSES);

        self::assertSame([], $this->tools($server));
    }

    public function testAnUnknownToolIsRejectedAndNothingIsWritten(): void
    {
        $server = $this->server();

        try {
            $this->store->setTools($server, ['create' => PolicyOutcome::Block, 'ghost' => PolicyOutcome::Auto], self::CLASSES);
            self::fail('expected an exception');
        } catch (McpToolPermissionException) {
            self::assertSame('{}', $server->getToolPolicy());
        }
    }

    public function testAnUnusableNotionToolCannotBeChanged(): void
    {
        $server = $this->server('{}', 'https://mcp.notion.com/mcp');

        $this->expectException(McpToolPermissionException::class);
        $this->store->setTools($server, ['notion-create-attachment' => PolicyOutcome::Auto], ['notion-create-attachment' => SideEffect::Destructive]);
    }

    public function testACategoryLeavesUnusableToolsAlone(): void
    {
        $server = $this->server('{}', 'https://mcp.notion.com/mcp');

        $this->store->setCategory($server, SideEffect::Destructive, PolicyOutcome::Auto, [
            'notion-create-attachment' => SideEffect::Destructive,
            'notion-delete-pages' => SideEffect::Destructive,
        ]);

        self::assertSame(['notion-delete-pages' => 'auto'], $this->tools($server));
    }

    public function testSeenKeepsTheChoicesAndDoesNotDuplicate(): void
    {
        $server = $this->server();
        $this->store->setTools($server, ['create' => PolicyOutcome::Block], self::CLASSES);

        $this->store->markSeen($server, ['search' => SideEffect::Read, 'create' => SideEffect::Write]);
        $this->store->markSeen($server, ['create' => SideEffect::Write, 'update' => SideEffect::Write]);

        self::assertSame(['search', 'create', 'update'], $this->store->seen($server));
        self::assertSame(['create' => 'block'], $this->tools($server));
    }

    public function testAnUnreadablePolicyKeepsEveryOtherWriteToolAsking(): void
    {
        $server = $this->server('not json');

        $this->store->setTools($server, ['create' => PolicyOutcome::Block], self::CLASSES);

        self::assertSame(['create' => 'block', 'update' => 'approve'], $this->tools($server));
    }

    public function testAnUnknownStoredValueStaysAskingWhenAnotherToolChanges(): void
    {
        $server = $this->server('{"v":1,"tools":{"update":"maybe"},"seen":[]}');

        $this->store->setTools($server, ['create' => PolicyOutcome::Block], self::CLASSES);

        self::assertSame(['update' => 'approve', 'create' => 'block'], $this->tools($server));
    }

    public function testSeenKeepsTheNewestNamesAtTheCap(): void
    {
        $old = array_map(static fn (int $i): string => 'old-'.$i, range(1, 1000));
        $server = $this->server(json_encode(['v' => 1, 'tools' => (object) [], 'seen' => $old], JSON_THROW_ON_ERROR));

        $this->store->markSeen($server, ['fresh' => SideEffect::Write]);

        $seen = $this->store->seen($server);
        self::assertCount(1000, $seen);
        self::assertContains('fresh', $seen);
        self::assertNotContains('old-1', $seen);
    }

    /**
     * @return array<string, string>
     */
    private function tools(McpServerConfig $server): array
    {
        $decoded = json_decode($server->getToolPolicy(), true);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['v']);

        return $decoded['tools'];
    }

    private function server(string $policy = '{}', string $url = 'https://example.com/mcp'): McpServerConfig
    {
        return (new McpServerConfig())->setUserId(1)->setUrl($url)->setToolPolicy($policy);
    }
}
