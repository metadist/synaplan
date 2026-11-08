<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool;

use App\Entity\Approval;
use App\Entity\User;
use App\Repository\ApprovalRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\Tool\ApprovalArgsRedactor;
use App\Service\Tool\ApprovalNotFoundException;
use App\Service\Tool\ApprovalRealtimeNotifier;
use App\Service\Tool\ApprovalService;
use App\Service\Tool\ToolsConfig;
use PHPUnit\Framework\TestCase;

final class ApprovalServiceFindForViewerTest extends TestCase
{
    public function testTheOwnerSeesThePreviewAndTheRequestBody(): void
    {
        $row = $this->service()->findForViewer($this->user(7, 'PRO'), 12);

        self::assertSame('Send secret-preview', $row['preview']);
        self::assertSame('secret-body', $row['resolvedRequest']['body']);
        self::assertTrue($row['canAlwaysAllow']);
        self::assertTrue($row['canDecide']);
        self::assertStringNotContainsString('secret-argument', (string) json_encode($row));
    }

    public function testAnAdminSeesTheOutcomeWithoutThePreviewOrTheRequest(): void
    {
        $row = $this->service()->findForViewer($this->user(9, 'ADMIN'), 12);
        $encoded = (string) json_encode($row);

        self::assertSame('create_ticket', $row['tool']);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['preview']);
        self::assertNull($row['resolvedRequest']);
        self::assertFalse($row['canAlwaysAllow']);
        self::assertFalse($row['canDecide']);
        self::assertStringNotContainsString('secret-preview', $encoded);
        self::assertStringNotContainsString('secret-body', $encoded);
        self::assertStringNotContainsString('secret-argument', $encoded);
    }

    public function testSomeoneElseGetsTheSameAnswerAsAMissingApproval(): void
    {
        $service = $this->service();

        try {
            $service->findForViewer($this->user(8, 'PRO'), 12);
            self::fail('Another person could open the approval');
        } catch (ApprovalNotFoundException $e) {
            self::assertSame('Approval 12 was not found', $e->getMessage());
        }

        $this->expectException(ApprovalNotFoundException::class);
        $service->findForViewer($this->user(9, 'ADMIN'), 99);
    }

    private function service(): ApprovalService
    {
        $approval = new Approval(7, 'chat:15', 'create_ticket', 'write', time() + 3600);
        $approval->setArgs([
            'title' => 'secret-argument',
            'resolvedRequest' => [
                'method' => 'POST',
                'url' => 'https://example.test/tickets',
                'headers' => [],
                'body' => 'secret-body',
            ],
        ]);
        $approval->setPreview('Send secret-preview');
        (new \ReflectionProperty(Approval::class, 'id'))->setValue($approval, 12);

        $approvals = $this->createStub(ApprovalRepository::class);
        $approvals->method('find')->willReturnCallback(
            static fn (int $id): ?Approval => 12 === $id ? $approval : null,
        );

        return new ApprovalService(
            $approvals,
            new ApprovalArgsRedactor(),
            $this->createStub(ToolsConfig::class),
            $this->createStub(ApprovalRealtimeNotifier::class),
            $this->createStub(AuditLogWriter::class),
        );
    }

    private function user(int $id, string $level): User
    {
        $user = new User();
        $user->setUserLevel($level);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
