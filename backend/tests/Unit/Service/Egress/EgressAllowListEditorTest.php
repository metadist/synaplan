<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Egress;

use App\Service\Admin\SystemConfig\SystemConfigWriter;
use App\Service\Egress\EgressAllowListEditor;
use App\Service\Egress\EgressAllowListException;
use App\Service\Egress\EgressConfig;
use App\Service\Iam\AuditLogWriter;
use PHPUnit\Framework\TestCase;

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

        $rules = (new EgressAllowListEditor(new EgressConfig(), $writer, $audit))->add('  HOOKS.example.org:8443 ', 7);

        self::assertSame(['crm.example.com', 'hooks.example.org:8443'], $rules);
    }

    public function testAllowingARuleThatIsAlreadyListedChangesNothing(): void
    {
        EgressConfig::setAllowOverride(['crm.example.com']);
        $writer = $this->createMock(SystemConfigWriter::class);
        $writer->expects(self::never())->method('write');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');

        $rules = (new EgressAllowListEditor(new EgressConfig(), $writer, $audit))->add('CRM.example.com', 7);

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

        $rules = (new EgressAllowListEditor(new EgressConfig(), $writer, $audit))->remove('crm.example.com', 3);

        self::assertSame(['*.files.example.net'], $rules);
    }

    public function testRemovingARuleThatIsNotListedChangesNothing(): void
    {
        EgressConfig::setAllowOverride(['crm.example.com']);
        $writer = $this->createMock(SystemConfigWriter::class);
        $writer->expects(self::never())->method('write');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');

        $rules = (new EgressAllowListEditor(new EgressConfig(), $writer, $audit))->remove('other.example.com', 3);

        self::assertSame(['crm.example.com'], $rules);
    }

    public function testAnInvalidRuleIsRefusedBeforeAnythingIsWritten(): void
    {
        EgressConfig::setAllowOverride([]);
        $writer = $this->createMock(SystemConfigWriter::class);
        $writer->expects(self::never())->method('write');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');

        try {
            (new EgressAllowListEditor(new EgressConfig(), $writer, $audit))->add('not a host/at all', 7);
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
            (new EgressAllowListEditor(new EgressConfig(), $writer, $audit))->add('crm.example.com', 7);
            self::fail('A failed save must be reported.');
        } catch (EgressAllowListException $e) {
            self::assertFalse($e->isInvalidRule());
        }
    }
}
