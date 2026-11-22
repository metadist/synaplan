<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Egress;

use App\AI\Catalog\ProviderCatalog;
use App\Observability\RequestTimelineContext;
use App\Repository\ConfigRepository;
use App\Service\Egress\EgressConfig;
use App\Service\Egress\EgressDestinationStanding;
use PHPUnit\Framework\TestCase;

final class EgressDestinationStandingTest extends TestCase
{
    private static ?ProviderCatalog $catalog = null;

    protected function tearDown(): void
    {
        EgressConfig::setAllowOverride(null);
    }

    public function testAProviderHostIsBuiltInAndOffersNoAction(): void
    {
        EgressConfig::setAllowOverride([]);

        self::assertSame(
            ['state' => 'builtin', 'rule' => null, 'allow_rule' => null],
            $this->standing()->of('api.openai.com:443'),
        );
    }

    public function testAnUnknownHostIsNotListedAndOffersItsHostAsTheRule(): void
    {
        EgressConfig::setAllowOverride([]);

        self::assertSame(
            ['state' => 'not_listed', 'rule' => null, 'allow_rule' => 'crm.example.com'],
            $this->standing()->of('crm.example.com:443'),
        );
        self::assertSame('hooks.example.org:8443', $this->standing()->of('hooks.example.org:8443')['allow_rule']);
    }

    public function testAnAdministratorEntryListsTheHostAndNamesTheEntryToRemove(): void
    {
        EgressConfig::setAllowOverride(['*.example.com']);

        self::assertSame(
            ['state' => 'listed', 'rule' => '*.example.com', 'allow_rule' => null],
            $this->standing()->of('crm.example.com:443'),
        );
    }

    public function testAllowedHostTurnsListedOnTheNextRead(): void
    {
        EgressConfig::setAllowOverride([]);
        $rule = $this->standing()->of('crm.example.com:443')['allow_rule'];
        self::assertNotNull($rule);

        EgressConfig::setAllowOverride([$rule]);

        self::assertSame('listed', $this->standing()->of('crm.example.com:443')['state']);
    }

    public function testTypedAddressesAreNeverOfferedForTheList(): void
    {
        EgressConfig::setAllowOverride([]);

        self::assertSame(
            ['state' => 'typed', 'rule' => null, 'allow_rule' => null],
            $this->standing()->of(RequestTimelineContext::TYPED_DESTINATION),
        );
    }

    public function testATypedCallIsRecordedWithoutItsHost(): void
    {
        $context = new RequestTimelineContext();
        $context->recordEgress('https://customer-secret.example/file?token=abc', 200, 40, true);
        $context->recordEgress('https://crm.example.com/api', 200, 12);

        $egress = $context->buildRecord('web1', '2xx', 0)['egress'];

        self::assertSame('typed', $egress[0]['destination']);
        self::assertSame('crm.example.com:443', $egress[1]['destination']);
    }

    public function testABatchReadsTheAllowListOnce(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects(self::once())
            ->method('getValuesForOwners')
            ->with([0], 'EGRESS', 'ALLOW')
            ->willReturn([0 => '["crm.example.com"]']);
        $standing = new EgressDestinationStanding(new EgressConfig($repository), self::$catalog ??= ProviderCatalog::builtIn());

        $standings = $standing->ofAll(['crm.example.com:443', 'hooks.example.org:8443', 'other.example.net:443']);

        self::assertSame('listed', $standings['crm.example.com:443']['state']);
        self::assertSame('not_listed', $standings['hooks.example.org:8443']['state']);
        self::assertSame('not_listed', $standings['other.example.net:443']['state']);
    }

    private function standing(): EgressDestinationStanding
    {
        return new EgressDestinationStanding(new EgressConfig(), self::$catalog ??= ProviderCatalog::builtIn());
    }
}
