<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Egress;

use App\Service\Admin\SystemConfig\SystemConfigWriter;
use App\Service\Egress\EgressAllowListEditor;
use App\Service\Egress\EgressAllowListException;
use App\Service\Egress\EgressConfig;
use App\Service\Iam\AuditLogWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class EgressAllowListEditorTest extends TestCase
{
    protected function tearDown(): void
    {
        EgressConfig::setAllowOverride(null);
    }

    public function testAllowAppendsTheNormalizedRuleSavesItAndLeavesOneAuditRow(): void
    {
        EgressConfig::setAllowOverride(['crm.example.com']);
        $writer = $this->createMock(SystemConfigWriter::class);
        $writer->expects(self::once())
            ->method('write')
            ->with('EGRESS_ALLOW', '["crm.example.com","hooks.example.org:8443"]', 7)
            ->willReturn(['success' => true, 'requiresRestart' => false]);
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())
            ->method('record')
            ->with(7, 'egress.allow_added', 'egress_rule', 'hooks.example.org:8443');

        $rules = self::editor($writer, $audit)->add('  HOOKS.example.org:8443 ', 7);

        self::assertSame(['crm.example.com', 'hooks.example.org:8443'], $rules);
    }

    public function testAllowingARuleThatIsAlreadyListedChangesNothing(): void
    {
        EgressConfig::setAllowOverride(['crm.example.com']);
        $writer = $this->createMock(SystemConfigWriter::class);
        $writer->expects(self::never())->method('write');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');

        $rules = self::editor($writer, $audit)->add('CRM.example.com', 7);

        self::assertSame(['crm.example.com'], $rules);
    }

    public function testRemoveTakesTheMatchingEntryOffAndAuditsIt(): void
    {
        EgressConfig::setAllowOverride(['crm.example.com', '*.files.example.net']);
        $writer = $this->createMock(SystemConfigWriter::class);
        $writer->expects(self::once())
            ->method('write')
            ->with('EGRESS_ALLOW', '["*.files.example.net"]', 3)
            ->willReturn(['success' => true, 'requiresRestart' => false]);
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())
            ->method('record')
            ->with(3, 'egress.allow_removed', 'egress_rule', 'crm.example.com');

        $rules = self::editor($writer, $audit)->remove('crm.example.com', 3);

        self::assertSame(['*.files.example.net'], $rules);
    }

    public function testRemovingARuleThatIsNotListedChangesNothing(): void
    {
        EgressConfig::setAllowOverride(['crm.example.com']);
        $writer = $this->createMock(SystemConfigWriter::class);
        $writer->expects(self::never())->method('write');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');

        $rules = self::editor($writer, $audit)->remove('other.example.com', 3);

        self::assertSame(['crm.example.com'], $rules);
    }

    #[DataProvider('invalidRules')]
    public function testAnInvalidRuleIsRefusedBeforeAnythingIsWritten(string $rule): void
    {
        EgressConfig::setAllowOverride([]);
        $writer = $this->createMock(SystemConfigWriter::class);
        $writer->expects(self::never())->method('write');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');

        try {
            self::editor($writer, $audit)->add($rule, 7);
            self::fail('An invalid rule must be refused.');
        } catch (EgressAllowListException $e) {
            self::assertTrue($e->isInvalidRule());
        }
    }

    public function testAFailedSaveIsReportedAndNotAudited(): void
    {
        EgressConfig::setAllowOverride([]);
        $writer = $this->createStub(SystemConfigWriter::class);
        $writer->method('write')->willReturn(['success' => false, 'requiresRestart' => false]);
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');

        try {
            self::editor($writer, $audit)->add('crm.example.com', 7);
            self::fail('A failed save must be reported.');
        } catch (EgressAllowListException $e) {
            self::assertFalse($e->isInvalidRule());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'a path' => ['not a host/at all'];
        yield 'spaces' => ['not a host'];
        yield 'a scheme' => ['https://crm.example.com'];
        yield 'a bad port' => ['crm.example.com:99999'];
        yield 'a leading hyphen' => ['-crm.example.com'];
    }

    #[DataProvider('validRules')]
    public function testHostFormsTheProxyUnderstandsAreAccepted(string $rule, string $saved): void
    {
        EgressConfig::setAllowOverride([]);
        $writer = $this->createStub(SystemConfigWriter::class);
        $writer->method('write')->willReturn(['success' => true, 'requiresRestart' => false]);

        $rules = self::editor($writer, $this->createStub(AuditLogWriter::class))->add($rule, 7);

        self::assertSame([$saved], $rules);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validRules(): iterable
    {
        yield 'one label' => ['redis', 'redis'];
        yield 'a docker name' => ['synaplan_backend:8000', 'synaplan_backend:8000'];
        yield 'a wildcard' => ['*.Files.example.net', '*.files.example.net'];
        yield 'a suffix' => ['.example.org', '.example.org'];
        yield 'IPv4 with a port' => ['10.0.0.5:8443', '10.0.0.5:8443'];
        yield 'IPv6 as Allow writes it' => ['::1:443', '::1:443'];
    }

    public function testTheListLockIsHeldDuringTheEditAndFreedAfterIt(): void
    {
        EgressConfig::setAllowOverride([]);
        $locks = new LockFactory(new InMemoryStore());
        $writer = $this->createStub(SystemConfigWriter::class);
        $writer->method('write')->willReturnCallback(static function () use ($locks): array {
            self::assertFalse($locks->createLock('egress-allow-list')->acquire(), 'Another edit must wait.');

            return ['success' => true, 'requiresRestart' => false];
        });

        (new EgressAllowListEditor(new EgressConfig(), $writer, $this->createStub(AuditLogWriter::class), $locks))
            ->add('crm.example.com', 7);

        self::assertTrue($locks->createLock('egress-allow-list')->acquire());
    }

    private static function editor(SystemConfigWriter $writer, AuditLogWriter $audit): EgressAllowListEditor
    {
        return new EgressAllowListEditor(new EgressConfig(), $writer, $audit, new LockFactory(new InMemoryStore()));
    }
}
