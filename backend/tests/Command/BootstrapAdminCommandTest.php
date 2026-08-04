<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BootstrapAdminCommand;
use App\Service\Admin\BootstrapAdminService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
final class BootstrapAdminCommandTest extends TestCase
{
    private BootstrapAdminService&MockObject $bootstrapAdminService;

    protected function setUp(): void
    {
        $this->bootstrapAdminService = $this->createMock(BootstrapAdminService::class);
    }

    public function testReportsUnconfiguredBootstrapAsSuccessfulNoOp(): void
    {
        $tester = $this->tester('', '');
        $this->bootstrapAdminService->expects($this->once())
            ->method('bootstrap')
            ->with('', '')
            ->willReturn(BootstrapAdminService::RESULT_NOT_CONFIGURED);

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('not configured', $tester->getDisplay());
    }

    public function testReportsExistingAdminAsSuccessfulNoOp(): void
    {
        $tester = $this->tester('admin@example.com', 'SecurePass123!');
        $this->bootstrapAdminService->method('bootstrap')
            ->willReturn(BootstrapAdminService::RESULT_ADMIN_EXISTS);

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testReportsCreatedAdminWithoutPrintingPassword(): void
    {
        $password = 'SecurePass123!';
        $tester = $this->tester('admin@example.com', $password);
        $this->bootstrapAdminService->method('bootstrap')
            ->willReturn(BootstrapAdminService::RESULT_CREATED);

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('administrator was created', $tester->getDisplay());
        $this->assertStringNotContainsString($password, $tester->getDisplay());
    }

    public function testReportsPromotedUser(): void
    {
        $tester = $this->tester('admin@example.com', 'SecurePass123!');
        $this->bootstrapAdminService->method('bootstrap')
            ->willReturn(BootstrapAdminService::RESULT_PROMOTED);

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('promoted to administrator', $tester->getDisplay());
    }

    public function testReturnsFailureForInvalidConfiguration(): void
    {
        $tester = $this->tester('admin@example.com', '');
        $this->bootstrapAdminService->method('bootstrap')
            ->willThrowException(new \InvalidArgumentException('Both bootstrap variables are required.'));

        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('First-admin bootstrap failed', $tester->getDisplay());
        $this->assertStringContainsString('Both bootstrap variables are required', $tester->getDisplay());
    }

    public function testSurfacesTheFullPasswordRuleGuidance(): void
    {
        $tester = $this->tester('admin@example.com', 'lowercase-only');
        $this->bootstrapAdminService->method('bootstrap')
            ->willThrowException(new \InvalidArgumentException('BOOTSTRAP_ADMIN_PASSWORD must contain at least one uppercase letter, one lowercase letter, and one number, or be at least 16 characters long.'));

        $tester->execute([]);

        // The error block wraps at the terminal width, so compare on a single line.
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('one uppercase letter, one lowercase letter, and one number, or be at least 16 characters long', $display);
    }

    private function tester(string $email, string $password): CommandTester
    {
        $application = new Application();
        $application->addCommand(new BootstrapAdminCommand(
            $this->bootstrapAdminService,
            $email,
            $password,
        ));

        return new CommandTester($application->find('app:bootstrap-admin'));
    }
}
