<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\Config;
use App\Entity\Share;
use App\Repository\ConfigRepository;
use App\Service\Iam\ResourceKind\ResourceCard;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;
use App\Service\Iam\ResourceKind\ShareableResourceKindInterface;
use App\Service\Iam\SharedInbox;
use App\Service\Iam\ShareService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SharedInboxOpenedMarkTest extends TestCase
{
    public function testNumericConversationIdSurvivesTheConfigRoundTrip(): void
    {
        /** @var array<string, string> $values */
        $values = [];
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting) use (&$values): ?string {
                return $values[$ownerId.'|'.$group.'|'.$setting] ?? null;
            }
        );
        $config->method('setValue')->willReturnCallback(
            function (int $ownerId, string $group, string $setting, string $value) use (&$values): Config {
                $values[$ownerId.'|'.$group.'|'.$setting] = $value;

                return $this->createMock(Config::class);
            }
        );

        $share = $this->createMock(Share::class);
        $share->method('getGrantedBy')->willReturn(9);
        $row = [
            'card' => new ResourceCard('13', 'Notes', 'chat'),
            'permission' => 'use',
            'ownerId' => 1,
            'share' => $share,
            'sharedAt' => 1_700_000_100,
        ];

        $shares = $this->createMock(ShareService::class);
        $shares->method('listSharedWith')->willReturn([$row]);

        $registry = $this->createMock(ResourceKindRegistry::class);
        $registry->method('get')->willReturn($this->createMock(ShareableResourceKindInterface::class));
        $lockDir = '/tmp/synaplan-opened-mark-'.bin2hex(random_bytes(4));
        mkdir($lockDir, 0700, true);
        $inbox = new SharedInbox($config, $shares, $registry, new LockFactory(new FlockStore($lockDir)));

        $inbox->markItemSeen(4, 'conversation', '13');

        self::assertFalse($inbox->rowIsNew($row, 4, 0, $inbox->openedMarks(4, 'conversation')));
        self::assertSame(0, $inbox->countUnseen(4, 'conversation'));
    }
}
